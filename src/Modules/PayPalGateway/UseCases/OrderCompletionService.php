<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\PayPalGateway\UseCases;

use ImperaPayPalHl\Core\Contract\LoggerInterface;
use ImperaPayPalHl\Modules\PayPalGateway\Responses\PaymentResponse;
use ImperaPayPalHl\Modules\PayPalGateway\Support\Calculator;
use RuntimeException;
use WC_Order;

/**
 * Writes a confirmed PayPal outcome onto a WooCommerce order.
 *
 * The same outcome has to be applied no matter who reported it: the customer
 * coming back through the browser, or the proxy's push landing after they closed
 * the tab. Keeping it in one place is what makes those two paths agree - and
 * what makes running twice harmless.
 *
 * The two often arrive in the same second. Each one is applied under a
 * per-order MySQL lock (atomic, released with the connection if PHP dies) to
 * an order read fresh from storage, so the second sees the first one's work:
 * one payment_complete(), one set of meta rows, never a paid order sent back.
 */
final class OrderCompletionService
{
    /** Seconds a writer waits for the other one to finish. */
    private const LOCK_TIMEOUT = 20;

    private LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = ImperaPPHContainer()->get(LoggerInterface::class);
    }

    /**
     * Idempotent. Returns true when the order is (or already was) settled, so a
     * caller can treat that as the final word.
     *
     * @param string $source Where the confirmation came from, for the note trail.
     */
    public function complete(WC_Order $order, PaymentResponse $paymentResponse, string $source): bool
    {
        global $wpdb;

        $orderId = $order->get_id();
        $lock    = 'impera_pph_order_' . $orderId;

        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_TIMEOUT)) !== 1) {
            throw new RuntimeException(sprintf('Order %d is busy (lock timeout) - PayPal result not applied (%s).', $orderId, $source));
        }

        try {
            return $this->apply($this->fresh($orderId), $paymentResponse, $source);
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /**
     * Straight from storage, not from the object cache or the caller's copy:
     * another request may have settled the order a moment ago.
     */
    private function fresh(int $orderId): WC_Order
    {
        clean_post_cache($orderId);
        wp_cache_delete($orderId, 'orders');
        wp_cache_delete('order-' . $orderId, 'orders');

        // HPOS keeps orders in its own cache under prefixed keys the deletes
        // above never reach: without this the "fresh" read is the copy this
        // request loaded before it took the lock.
        if (class_exists(\Automattic\WooCommerce\Caches\OrderCache::class)) {
            wc_get_container()->get(\Automattic\WooCommerce\Caches\OrderCache::class)->remove($orderId);
        }

        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            throw new RuntimeException(sprintf('Order %d not found.', $orderId));
        }

        return $order;
    }

    public const OPERATION_MISMATCH = 'operation_mismatch';
    public const MODE_MISMATCH      = 'mode_mismatch';
    public const CURRENCY_MISMATCH  = 'currency_mismatch';
    public const AMOUNT_MISMATCH    = 'amount_mismatch';
    public const INCOMPLETE_RESULT  = 'incomplete_result';
    public const STATUS_CONFLICT    = 'status_conflict';

    private function apply(WC_Order $order, PaymentResponse $paymentResponse, string $source): bool
    {
        // The operation first, for every result - a refund, a decline or a
        // payment alike: this gateway, this WooCommerce order, the PayPal order
        // stored on it, the environment it was made in. Money is checked below
        // for results that put money on the order.
        $reason = $this->validate($order, $paymentResponse, false);

        if ($reason === null && $paymentResponse->isPaid()) {
            $reason = $this->validate($order, $paymentResponse, true);
        }

        if ($reason !== null) {
            return $this->refuse($order, $paymentResponse, $source, $reason);
        }

        // Checked before is_paid(): a refund is news precisely about an order
        // that was paid, and returning early on those would drop every refund
        // made in the PayPal dashboard.
        if ($paymentResponse->isRefunded()) {
            $this->logger->debug('OrderCompletionService::complete: order is refunded, not recording it.');
            return $this->recordRefund($order, $paymentResponse, $source);
        }

        // Already collected. A refresh, a double redirect, or a webhook racing
        // the browser must never charge or transition twice.
        if ($order->is_paid()) {
            $this->logger->debug('OrderCompletionService::complete: order already paid, not recording it.');
            return true;
        }

        // Money arriving for an order nobody can settle any more. Refusing is
        // right, but staying quiet is not: the customer really has been charged.
        if ($order->has_status(['cancelled', 'refunded'])) {
            $this->logger->debug('OrderCompletionService::complete: order is cancelled or refunded, not recording it.');
            $this->reportMoneyAgainstClosedOrder($order, $paymentResponse, $source);

            return false;
        }

        if ($paymentResponse->isAmountMismatch()) {
            $this->logger->debug('OrderCompletionService::complete: order amount mismatch, not recording it.');
            $this->reportAmountMismatch($order, $paymentResponse, $source);

            return false;
        }

        // The proxy verifies the amount too, but it compares against what it was
        // asked for. This compares against what the order is worth now - a total
        // that changed after the payment started must not settle either.
        if ($paymentResponse->isPaid() && !$this->amountMatchesOrder($order, $paymentResponse)) {
            $this->logger->debug('OrderCompletionService::complete: order amount mismatch, not recording it.');
            $this->reportAmountMismatch($order, $paymentResponse, $source);

            return false;
        }

        return match (true) {
            $paymentResponse->isPaid()           => $this->settle($order, $paymentResponse, $source),
            $paymentResponse->isPending()        => $this->hold($order, $paymentResponse, $source),
            $paymentResponse->needsPayerAction() => $this->awaitPayerAction($order, $paymentResponse, $source),
            $paymentResponse->isCancelled()      => $this->cancel($order, $paymentResponse, $source),
            $paymentResponse->isFailed()         => $this->fail($order, $paymentResponse, $source),
            default                              => $this->noteOnly($order, $paymentResponse, $source),
        };
    }

    /**
     * Null when the result belongs to this order and agrees with it, otherwise
     * the reason. A missing field is never read as success.
     */
    private function validate(WC_Order $order, PaymentResponse $response, bool $checkMoney): ?string
    {
        if ($order->get_payment_method() !== IMPERA_PPH_GATEWAY_ID) {
            return self::OPERATION_MISMATCH;
        }

        if ((int) $response->getWcOrderId() !== $order->get_id()) {
            return self::OPERATION_MISMATCH;
        }

        $storedPpOrder = (string) $order->get_meta(IMPERA_WC_ORDER_PPH_ORDER_ID);

        if ($storedPpOrder === '' || $response->getPpOrderId() === '' || !hash_equals($storedPpOrder, $response->getPpOrderId())) {
            return self::OPERATION_MISMATCH;
        }

        $mode       = $response->getMode();
        $storedMode = (string) $order->get_meta(IMPERA_WC_ORDER_PPH_MODE);

        if (!in_array($mode, ['sandbox', 'production'], true)) {
            return self::INCOMPLETE_RESULT;
        }

        if ($storedMode === '' || $mode !== $storedMode) {
            return self::MODE_MISMATCH;
        }

        if (!$checkMoney) {
            return null;
        }

        // "paid" with success:false is a contradiction, not a payment.
        if (!$response->isSuccess()) {
            return self::STATUS_CONFLICT;
        }

        $currency = $response->getCurrency();

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1 || (string) $response->getCaptureId() === '') {
            return self::INCOMPLETE_RESULT;
        }

        if ($currency !== strtoupper((string) $order->get_currency())) {
            return self::CURRENCY_MISMATCH;
        }

        if ($response->grossAmountMinor() <= 0) {
            return self::INCOMPLETE_RESULT;
        }

        if (!$this->amountMatchesOrder($order, $response)) {
            return self::AMOUNT_MISMATCH;
        }

        return null;
    }

    /**
     * Not applied. A paid or closed order keeps everything it has - a note
     * only; an unpaid one goes on hold for a human when the result speaks of
     * money, and nothing ships. No automatic refund: a wrong binding would
     * refund someone else's payment.
     */
    private function refuse(WC_Order $order, PaymentResponse $response, string $source, string $reason): bool
    {
        $note = sprintf(
            'PayPal result NOT applied (%s): %s. PayPal order %s, capture %s, %s %s, mode %s, status %s.',
            $source,
            $reason,
            $response->getPpOrderId() ?: '-',
            (string) ($response->getCaptureId() ?: '-'),
            $response->grossAmount(),
            $response->getCurrency() ?: '-',
            $response->getMode() ?: '-',
            $response->getPaymentStatus() ?: '-'
        );

        $this->logger->error(sprintf('Order %d: %s', $order->get_id(), $note));

        if (!$order->is_paid() && !$order->has_status(['cancelled', 'refunded'])
            && ($response->isPaid() || $response->isPending())) {
            $order->has_status('on-hold')
                ? $order->add_order_note($note . ' Manual reconciliation required.')
                : $order->update_status('on-hold', $note . ' Manual reconciliation required.');

            return false;
        }

        $order->add_order_note($note);

        return false;
    }

    /**
     * The money is collected: write it down and let WooCommerce do the rest.
     *
     * payment_complete() is called instead of update_status('processing'), never
     * after one. Doing both reduces stock twice and sends two emails.
     */
    private function settle(WC_Order $order, PaymentResponse $paymentResponse, string $source): bool
    {
        $this->saveMeta($order, $paymentResponse);

        $order->add_order_note(sprintf('Save Order Metadata From PayPal (%s)', $source));

        if ($paymentResponse->getMode() === 'sandbox') {
            $order->add_order_note('THIS IS A TEST ORDER!!!!');
        }

        $order->add_order_note(sprintf(
            'PayPal charged by proxy %s',
            (string) $order->get_meta(IMPERA_WC_ORDER_PPH_PROXY_DOMAIN)
        ));
        $order->add_order_note((string) $paymentResponse);
        $this->logger->info(sprintf('Order %d settled by PayPal (%s)', $order->get_id(), $source));
        $order->payment_complete((string) $paymentResponse->getCaptureId());

        $order->add_order_note(sprintf('Order PayPal Payment Complete (%s)', $source));

        return true;
    }

    /**
     * PayPal has the money but has not cleared it - an eCheck, or a review. Real
     * enough to stop the order being paid again, not enough to ship on.
     */
    private function hold(WC_Order $order, PaymentResponse $paymentResponse, string $source): bool
    {
        $this->saveMeta($order, $paymentResponse);

        if (!$order->has_status('on-hold')) {
            $this->logger->info(sprintf('Set on-hold PayPal  has not cleared the payment yet source: %s | Reason: %s', $source, $paymentResponse->getErrorMessage() ?: 'not given'));
            $order->update_status('on-hold', sprintf(
                'PayPal has not cleared the payment yet (%s). Reason: %s',
                $source,
                $paymentResponse->getErrorMessage() ?: 'not given'
            ));
        }

        return false;
    }

    /**
     * INSTRUMENT_DECLINED and friends. PayPal is asking the buyer to choose
     * another funding source in the same order, so the order is left payable and
     * the fresh approval link is stored for them to be sent back to.
     */
    private function awaitPayerAction(WC_Order $order, PaymentResponse $paymentResponse, string $source): bool
    {
        if ($paymentResponse->getApproveUrl()) {
            $order->update_meta_data(IMPERA_WC_ORDER_PPH_APPROVE_URL, $paymentResponse->getApproveUrl());
            $order->save_meta_data();
        }

        $order->add_order_note(sprintf(
            'PayPal needs another action from the buyer (%s): %s. The order is still payable.',
            $source,
            $paymentResponse->getErrorMessage() ?: 'instrument declined'
        ));

        return false;
    }

    private function cancel(WC_Order $order, PaymentResponse $paymentResponse, string $source): bool
    {
        $order->add_order_note(sprintf('PayPal payment cancelled or voided (%s).', $source));

        return false;
    }

    private function fail(WC_Order $order, PaymentResponse $paymentResponse, string $source): bool
    {
        $message = sprintf(
            'PayPal payment was not completed (%s). Order status: %s, capture status: %s, message: %s',
            $source,
            (string) $paymentResponse->getOrderStatus(),
            (string) $paymentResponse->getCaptureStatus(),
            (string) $paymentResponse->getErrorMessage()
        );

        $order->add_order_note($message);
        $this->logger->error($message);

        if (!$order->has_status('failed')) {
            $order->update_status('failed');
        }

        return false;
    }

    /**
     * A refund PayPal knows about. Usually one made in the PayPal dashboard, but
     * also the echo of a refund this shop itself started.
     *
     * PayPal's running total is the source of truth, and WooCommerce's own total
     * is what tells us how much of it has already been written down. Only the
     * difference becomes a refund here - which is what keeps a refund made in
     * the WooCommerce admin, and the webhook that follows it moments later, from
     * being counted twice.
     */
    private function recordRefund(WC_Order $order, PaymentResponse $paymentResponse, string $source): bool
    {
        $currency = $order->get_currency();

        $order->update_meta_data(IMPERA_WC_ORDER_PPH_REFUNDED, $paymentResponse->refundedAmount());
        $order->save_meta_data();

        $paypalTotal = Calculator::toMinor($paymentResponse->refundedAmount(), $currency);
        $wooTotal    = Calculator::toMinor((string) $order->get_total_refunded(), $currency);

        $outstanding = $paypalTotal - $wooTotal;

        if ($outstanding <= 0) {
            // Already recorded on this side - our own refund coming back to us.
            return false;
        }

        $amount = Calculator::fromMinor($outstanding, $currency);

        $refund = wc_create_refund([
            'amount'     => $amount,
            'reason'     => sprintf('Refunded on PayPal (%s)', $source),
            'order_id'   => $order->get_id(),
            // No line items: PayPal credits an amount, not a basket, and guessing
            // which lines it meant would put the wrong numbers on the order.
            'refund_payment' => false,
        ]);

        if (is_wp_error($refund)) {
            $message = sprintf(
                'PayPal reports %s %s refunded on order %d but WooCommerce refused to record it (%s): %s',
                $paymentResponse->refundedAmount(),
                $paymentResponse->getCurrency(),
                $order->get_id(),
                $source,
                $refund->get_error_message()
            );

            $order->add_order_note($message);
            $this->logger->error($message);

            return false;
        }

        $order->add_order_note(sprintf(
            'PayPal reports %s %s refunded on this payment in total; %s %s of it recorded here (%s).',
            $paymentResponse->refundedAmount(),
            $paymentResponse->getCurrency(),
            $amount,
            $currency,
            $source
        ));

        return false;
    }

    private function noteOnly(WC_Order $order, PaymentResponse $paymentResponse, string $source): bool
    {
        $order->add_order_note(sprintf(
            'PayPal payment not completed (%s). Status: %s, order: %s, capture: %s',
            $source,
            $paymentResponse->getPaymentStatus(),
            (string) $paymentResponse->getOrderStatus(),
            (string) $paymentResponse->getCaptureStatus()
        ));

        return false;
    }

    private function saveMeta(WC_Order $order, PaymentResponse $paymentResponse): void
    {
        $order->update_meta_data(IMPERA_WC_ORDER_PPH_ORDER_ID, $paymentResponse->getPpOrderId());
        $order->update_meta_data(IMPERA_WC_ORDER_PPH_CAPTURE_ID, (string) $paymentResponse->getCaptureId());
        $order->update_meta_data(IMPERA_WC_ORDER_PPH_PAYMENT_SOURCE, (string) $paymentResponse->getPaymentSource());
        $order->update_meta_data(IMPERA_WC_ORDER_PPH_GROSS_AMOUNT, $paymentResponse->grossAmount());
        $order->update_meta_data(IMPERA_WC_ORDER_PPH_FEE, $paymentResponse->paypalFee());
        $order->update_meta_data(IMPERA_WC_ORDER_PPH_NET_AMOUNT, $paymentResponse->netAmount());
        $order->update_meta_data(IMPERA_WC_ORDER_PPH_REFUNDED, $paymentResponse->refundedAmount());
        $order->update_meta_data(IMPERA_WC_ORDER_PPH_CURRENCY, $paymentResponse->getCurrency());
        $order->update_meta_data(IMPERA_WC_ORDER_PPH_MODE, $paymentResponse->getMode());
        $order->save_meta_data();
    }

    /**
     * Compared in minor units, and in the order's own currency: a float compare
     * would let a cent of rounding decide whether an order gets paid.
     */
    private function amountMatchesOrder(WC_Order $order, PaymentResponse $paymentResponse): bool
    {
        if (strtoupper($order->get_currency()) !== strtoupper($paymentResponse->getCurrency())) {
            return false;
        }

        return Calculator::equals(
            $order->get_total(),
            $paymentResponse->grossAmount(),
            $order->get_currency()
        );
    }

    private function reportAmountMismatch(WC_Order $order, PaymentResponse $paymentResponse, string $source): void
    {
        // No capture means the proxy refused the order before charging it.
        $message = (string) $paymentResponse->getCaptureId() === '' ? sprintf(
            'PayPal order %s is for %s %s, but order %d is for %s %s (%s). Nothing was charged; the payment was refused.',
            $paymentResponse->getPpOrderId(),
            $paymentResponse->grossAmount(),
            $paymentResponse->getCurrency(),
            $order->get_id(),
            (string) $order->get_total(),
            $order->get_currency(),
            $source
        ) : sprintf(
            'PayPal reports %s %s collected for order %d, which is for %s %s (%s). The order was NOT marked paid - reconcile it by hand. PayPal order: %s, capture: %s.',
            $paymentResponse->grossAmount(),
            $paymentResponse->getCurrency(),
            $order->get_id(),
            (string) $order->get_total(),
            $order->get_currency(),
            $source,
            $paymentResponse->getPpOrderId(),
            (string) $paymentResponse->getCaptureId()
        );

        $order->add_order_note($message);
        $this->logger->error($message);
    }

    private function reportMoneyAgainstClosedOrder(WC_Order $order, PaymentResponse $paymentResponse, string $source): void
    {
        $order->add_order_note(sprintf(
            'Ignored a %s payment update for a %s order.',
            $source,
            $order->get_status()
        ));

        if (!$paymentResponse->isPaid() && !$paymentResponse->isPending()) {
            return;
        }

        $message = sprintf(
            'PayPal reports order %d as %s while the order is %s (%s). PayPal order: %s, capture: %s. This needs to be reconciled by hand.',
            $order->get_id(),
            $paymentResponse->getPaymentStatus(),
            $order->get_status(),
            $source,
            $paymentResponse->getPpOrderId(),
            (string) $paymentResponse->getCaptureId()
        );

        $order->add_order_note($message);
        $this->logger->error($message);
    }
}
