<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\PayPalGateway\RestApi;

use ImperaPayPalHl\Core\Contract\LoggerInterface;
use ImperaPayPalHl\Core\RestApi\Action;
use ImperaPayPalHl\Core\RestApi\Contract\AbstractRoute;
use ImperaPayPalHl\Core\Support\Utils;
use ImperaPayPalHl\Modules\PayPalGateway\Responses\PaymentResponse;
use ImperaPayPalHl\Modules\PayPalGateway\UseCases\OrderCompletionService;
use ImperaPayPalHl\Modules\PayPalGateway\WcGatewaySettings;
use Throwable;
use WC_Order;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The proxy pushes a PayPal outcome here as soon as its own webhook fires.
 *
 * This is what saves an order whose customer paid and then closed the tab before
 * the browser redirect could land: the proxy confirms the money with PayPal and
 * tells the shop directly, instead of the shop waiting for a return that never
 * comes.
 *
 * Server-to-server: authenticated by the shared HMAC key, not a WP nonce.
 */
final class PaymentUpdateRoute extends AbstractRoute
{
    public const BASE_URL = '/paypal';

    private LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = ImperaPPHContainer()->get(LoggerInterface::class);
    }

    public function defineActions(): array
    {
        return [
            Action::post(self::BASE_URL . '/payment-update', [$this, 'paymentUpdate']),
        ];
    }

    public function paymentUpdate(WP_REST_Request $request): WP_REST_Response
    {
        $params    = (array) $request->get_json_params();
        $wcOrderId = (int) ($params['wc_order_id'] ?? 0);
        $ppOrderId = (string) ($params['pp_order_id'] ?? '');

        $order = $this->resolveOrder($wcOrderId, $ppOrderId);

        if (!$order instanceof WC_Order) {
            // 200 on purpose - the payment is real and recorded on the proxy, so
            // a retry storm would not help. The mismatch is logged for a human.
            return new WP_REST_Response([
                'success'       => false,
                'error_message' => 'Order not found or PayPal order mismatch.',
            ], 200);
        }

        try {
            $paymentResponse = PaymentResponse::fromArray($params);

            $settled = ImperaPPHContainer()
                ->get(OrderCompletionService::class)
                ->complete($order, $paymentResponse, 'proxy-webhook');

            return new WP_REST_Response([
                'success'     => $settled,
                'wc_order_id' => $order->get_id(),
                'status'      => $order->get_status(),
            ], 200);
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                '%s | PayPal payment-update failed | WC ORDER ID: %d | PayPal Order: %s',
                $e->getMessage(),
                $wcOrderId,
                $ppOrderId
            ));

            // 500 asks the proxy to retry - the payment is confirmed, only our
            // side failed.
            return new WP_REST_Response([
                'success'       => false,
                'error_message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * The HMAC already proves the caller is the proxy; matching the stored
     * PayPal order id on top of that stops one order's push from ever touching
     * another.
     */
    private function resolveOrder(int $wcOrderId, string $ppOrderId): ?WC_Order
    {
        if ($wcOrderId === 0) {
            return null;
        }

        $order = wc_get_order($wcOrderId);

        if (!$order instanceof WC_Order) {
            $this->logger->error(sprintf('PayPal payment-update: order %d not found.', $wcOrderId));

            return null;
        }

        $storedOrderId = (string) $order->get_meta(IMPERA_WC_ORDER_PPH_ORDER_ID);

        if ($ppOrderId !== '' && $storedOrderId !== '' && !hash_equals($storedOrderId, $ppOrderId)) {
            $this->logger->error(sprintf(
                'PayPal payment-update: PayPal order mismatch for WC order %d (stored %s, got %s).',
                $wcOrderId,
                $storedOrderId,
                $ppOrderId
            ));

            return null;
        }

        return $order;
    }

    public function permissionCallback(WP_REST_Request $request): bool
    {
        $sharedKey = ImperaPPHContainer()->get(WcGatewaySettings::class)->getSharedKey();

        // Fail closed: a shop that has not been given a key must not accept a
        // push that marks one of its orders paid.
        if ($sharedKey === '') {
            return false;
        }

        return Utils::isValidRequestSignature($request, $sharedKey);
    }

    /**
     * The proxy cannot present a WP nonce; the shared-key signature is the trust
     * anchor. Without this override AbstractRoute would demand one and reject
     * every push with a 403.
     */
    public function globalPermissionMiddleware(): array
    {
        return [];
    }
}
