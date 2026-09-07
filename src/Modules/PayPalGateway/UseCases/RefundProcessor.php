<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\PayPalGateway\UseCases;

use ImperaPayPalHl\Core\Contract\LoggerInterface;
use ImperaPayPalHl\Core\Support\Utils;
use ImperaPayPalHl\Modules\PayPalGateway\DTO\RefundOrderDto;
use ImperaPayPalHl\Modules\PayPalGateway\Exceptions\RefundProcessException;
use ImperaPayPalHl\Modules\PayPalGateway\Responses\RefundResponse;
use ImperaPayPalHl\Modules\PayPalGateway\Support\Calculator;
use ImperaPayPalHl\Modules\PayPalGateway\WcGatewaySettings;
use WC_Order;

/**
 * Refunds go through the proxy and against the PayPal capture - there is no
 * endpoint that credits an order, and the capture id is what WooCommerce
 * already stores as the order's transaction id.
 */
final class RefundProcessor
{
    private const ENDPOINT = '/?rest_route=/impera-proxy-payment/v1/paypal-redirect/refund-order';

    private LoggerInterface $logger;

    private WcGatewaySettings $settings;

    public function __construct()
    {
        $this->logger = ImperaPPHContainer()->get(LoggerInterface::class);

        $this->settings = ImperaPPHContainer()->get(WcGatewaySettings::class);
    }

    public function process(WC_Order $order, $amount = null, string $reason = ''): RefundResponse
    {
        $this->assertAmountIsValid($order, $amount);

        $response = $this->sendRefundRequest($order, $this->buildRefundDto($order, $amount, $reason));

        $this->assertResponseIsSuccess($order, $response);

        $this->applyRefundToOrder($order, $response);

        return $response;
    }

    private function sendRefundRequest(WC_Order $order, RefundOrderDto $dto): RefundResponse
    {
        $proxyDomain = (string) $order->get_meta(IMPERA_WC_ORDER_PPH_PROXY_DOMAIN);

        if ($proxyDomain === '') {
            throw new RefundProcessException('The order has no PayPal proxy domain stored, refund is impossible.');
        }

        $sharedKey = $this->settings->getSharedKey();

        if ($sharedKey === '') {
            throw new RefundProcessException('The PayPal gateway has no shared key configured, refund is impossible.');
        }

        $body = (string) wp_json_encode($dto->jsonSerialize());

        $response = wp_remote_post(Utils::buildDomain($proxyDomain) . self::ENDPOINT, [
            'sslverify' => true,
            'timeout'   => 45,
            'headers'   => [
                'Content-Type' => 'application/json',
                'X-Signature'  => hash_hmac('sha256', $body, $sharedKey),
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            $message = $response->get_error_message();

            $this->logFailure($order, $message, ['code' => $response->get_error_code()]);

            throw new RefundProcessException(sprintf('We cannot refund the order: %s', $message));
        }

        return RefundResponse::fromArray(Utils::getResponseJson($response));
    }

    /**
     * WooCommerce has already checked the refund against the order total; this
     * checks it against what PayPal actually collected, which is not always the
     * same number - and is the only one PayPal will credit.
     */
    private function assertAmountIsValid(WC_Order $order, $amount): void
    {
        $currency    = (string) ($order->get_meta(IMPERA_WC_ORDER_PPH_CURRENCY) ?: $order->get_currency());
        $grossAmount = $order->get_meta(IMPERA_WC_ORDER_PPH_GROSS_AMOUNT);

        if (empty($grossAmount)) {
            throw new RefundProcessException('PayPal gross amount is not set on this order.');
        }

        if ($amount === null || Calculator::toMinor($amount, $currency) <= 0) {
            throw new RefundProcessException('Refund amount must be greater than zero.');
        }

        $alreadyRefunded = Calculator::toMinor((string) $order->get_meta(IMPERA_WC_ORDER_PPH_REFUNDED), $currency);

        if (Calculator::toMinor($amount, $currency) + $alreadyRefunded > Calculator::toMinor($grossAmount, $currency)) {
            throw new RefundProcessException('Refund amount exceeds the amount charged by PayPal.');
        }
    }

    private function buildRefundDto(WC_Order $order, $amount, string $reason): RefundOrderDto
    {
        return RefundOrderDto::fromArray([
            'capture_id'    => $order->get_meta(IMPERA_WC_ORDER_PPH_CAPTURE_ID),
            'pp_order_id'   => $order->get_meta(IMPERA_WC_ORDER_PPH_ORDER_ID),
            'wc_order_id'   => $order->get_id(),
            'merchant_site' => Utils::homeDomain(),
            'amount'        => $amount,
            'currency'      => $order->get_meta(IMPERA_WC_ORDER_PPH_CURRENCY) ?: $order->get_currency(),
            'mode'          => $order->get_meta(IMPERA_WC_ORDER_PPH_MODE) ?: 'production',
            'reason'        => $reason ?: 'Without reason',
        ]);
    }

    private function assertResponseIsSuccess(WC_Order $order, RefundResponse $response): void
    {
        if ($response->isSuccess()) {
            return;
        }

        $this->logFailure($order, $response->toString());

        throw new RefundProcessException($response->toString());
    }

    private function applyRefundToOrder(WC_Order $order, RefundResponse $response): void
    {
        $currentUser = wp_get_current_user();

        $message = sprintf('Order refund was initiated by user %s (ID: %d)', $currentUser->user_login, $currentUser->ID);

        $this->logger->info($message);

        $order->add_order_note($message);
        $order->add_order_note($response->toString());

        $order->update_meta_data(IMPERA_WC_ORDER_PPH_REFUND_USER, $currentUser->ID);
        $order->update_meta_data(IMPERA_WC_ORDER_PPH_REFUND_ID, (string) $response->getRefundId());

        // PayPal's own running total, never a sum kept here: adding refunds up
        // locally double-counts one that was also made in the PayPal dashboard.
        if ($response->getRefundedTotal() !== null) {
            $order->update_meta_data(IMPERA_WC_ORDER_PPH_REFUNDED, $response->getRefundedTotal());
        }

        $order->save_meta_data();
    }

    private function logFailure(WC_Order $order, string $message, array $context = []): void
    {
        $order->add_order_note($message);
        $this->logger->error($message, $context + ['wc_order_id' => $order->get_id()]);
    }
}
