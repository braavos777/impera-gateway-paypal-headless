<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\PayPalGateway\UseCases;

use ImperaPayPalHl\Core\Contract\LoggerInterface;
use ImperaPayPalHl\Modules\PayPalGateway\WcGatewaySettings;
use Throwable;
use WC_Order;

/**
 * Finds out what happened to a PayPal payment whose outcome is not known - the
 * proxy's answer to a capture (or to the status read that captures on the
 * customer's return) never arrived, or said "outcome unknown".
 *
 * Asks again for the stored PayPal order, in the stored environment. Both calls
 * are safe to repeat: PayPal captures an order once, and the proxy answers an
 * already captured order with its capture.
 *  - redirect flow: order-status with capture (what the return would have done);
 *  - embedded flow: the signed capture again, with this order's own total.
 * The answer goes through OrderCompletionService (lock, fresh read, validator)
 * like any other. Still unknown -> asked again later (DELAYS); after the last
 * check the order stays on hold with a note for a human.
 */
final class PaymentReconciler
{
    public const HOOK = 'impera_pph_reconcile';

    public const META_UNKNOWN = '_impera_pph_unknown';

    /** Which flow made the PayPal order: 'redirect' or 'iframe'. */
    public const META_FLOW = '_impera_pph_flow';

    public const OUTCOME_SETTLED = 'settled';
    public const OUTCOME_OPEN    = 'open';
    public const OUTCOME_UNKNOWN = 'unknown';

    /** Seconds before check n (0-based). */
    public const DELAYS = [120, 600, 1800, 7200, 43200];

    public function __construct(
        private readonly PaymentProcessor $processor,
        private readonly OrderCompletionService $completion,
        private readonly WcGatewaySettings $settings,
        private readonly LoggerInterface $logger
    ) {}

    public static function register(): void
    {
        add_action(self::HOOK, static function ($orderId, $attempt = 0): void {
            ImperaPPHContainer()->get(self::class)->reconcile((int) $orderId, (int) $attempt);
        }, 10, 2);
    }

    /**
     * Holds the order as "payment being verified" and schedules the first check.
     */
    public function markUnknown(WC_Order $order, string $why): void
    {
        $order->update_meta_data(self::META_UNKNOWN, (string) time());
        $order->save_meta_data();

        $note = sprintf(
            'PayPal payment status unknown (%s) - verifying PayPal order %s before anything else. Do not ask the buyer to pay again.',
            $why,
            (string) $order->get_meta(IMPERA_WC_ORDER_PPH_ORDER_ID)
        );

        if (!$order->is_paid()) {
            $order->has_status('on-hold') ? $order->add_order_note($note) : $order->update_status('on-hold', $note);
        }

        $this->logger->warn(sprintf('Order %d: %s', $order->get_id(), $note));

        $this->schedule($order->get_id(), 0);
    }

    public static function isUnknown(WC_Order $order): bool
    {
        return (string) $order->get_meta(self::META_UNKNOWN) !== '';
    }

    public function reconcile(int $orderId, int $attempt = 0, bool $reschedule = true): string
    {
        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order || $order->get_payment_method() !== IMPERA_PPH_GATEWAY_ID) {
            return self::OUTCOME_UNKNOWN;
        }

        if ($order->is_paid() || $order->has_status(['refunded', 'cancelled'])) {
            $this->clear($order);

            return self::OUTCOME_SETTLED;
        }

        $ppOrderId = (string) $order->get_meta(IMPERA_WC_ORDER_PPH_ORDER_ID);
        $mode      = (string) $order->get_meta(IMPERA_WC_ORDER_PPH_MODE);

        if ($ppOrderId === '' || !in_array($mode, ['sandbox', 'production'], true)) {
            return $this->retry($order, $attempt, $reschedule, 'no PayPal order or environment stored on the order');
        }

        try {
            $response = (string) $order->get_meta(self::META_FLOW) === 'iframe'
                ? $this->processor->captureFrameOrder($order, $ppOrderId, '', $this->settings)
                : $this->processor->fetchOrderStatus($order, $ppOrderId, $mode, true);

            $settled = $this->completion->complete($order, $response, 'status-check');
        } catch (Throwable $e) {
            return $this->retry($order, $attempt, $reschedule, $e->getMessage());
        }

        $order = wc_get_order($orderId) ?: $order;

        if ($settled || $order->is_paid() || $order->has_status(['failed', 'cancelled', 'refunded'])) {
            $this->clear($order);

            return self::OUTCOME_SETTLED;
        }

        // A definite answer that settles nothing yet (pending review, payer
        // action): PayPal's own webhook push will finish it.
        $this->clear($order);

        return self::OUTCOME_OPEN;
    }

    private function retry(WC_Order $order, int $attempt, bool $reschedule, string $why): string
    {
        if (!$reschedule) {
            return self::OUTCOME_UNKNOWN;
        }

        $next = $attempt + 1;

        if ($next < count(self::DELAYS)) {
            $this->schedule($order->get_id(), $next);

            return self::OUTCOME_UNKNOWN;
        }

        $note = sprintf(
            'PayPal payment status still unknown after %d checks (%s) - manual reconciliation required. Do not ask the buyer to pay again before it is resolved.',
            count(self::DELAYS),
            $why
        );

        $order->add_order_note($note);
        $this->logger->error(sprintf('Order %d: %s', $order->get_id(), $note));

        return self::OUTCOME_UNKNOWN;
    }

    private function schedule(int $orderId, int $attempt): void
    {
        $when = time() + self::DELAYS[$attempt];
        $args = [$orderId, $attempt];

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action($when, self::HOOK, $args, 'impera-pph');

            return;
        }

        wp_schedule_single_event($when, self::HOOK, $args);
    }

    private function clear(WC_Order $order): void
    {
        if (self::isUnknown($order)) {
            $order->delete_meta_data(self::META_UNKNOWN);
            $order->save_meta_data();
        }
    }
}
