<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\PayPalGateway\UseCases;

use ImperaPayPalHl\Core\Contract\LoggerInterface;
use ImperaPayPalHl\Core\Support\Utils;
use ImperaPayPalHl\Modules\PayPalGateway\DTO\CreateOrderDto;
use ImperaPayPalHl\Modules\PayPalGateway\DTO\OrderStatusDto;
use ImperaPayPalHl\Modules\PayPalGateway\Exceptions\PaymentProcessException;
use ImperaPayPalHl\Modules\PayPalGateway\Responses\CreateOrderResponse;
use ImperaPayPalHl\Modules\PayPalGateway\Responses\PaymentResponse;
use ImperaPayPalHl\Modules\PayPalGateway\WcGatewaySettings;
use WC_Order;
use WP_Error;

/**
 * Asks the proxy to create a PayPal order and hands WooCommerce the URL the
 * customer has to be redirected to. No PayPal call ever happens here - the shop
 * holds no credentials.
 */
final class PaymentProcessor
{
    private const CREATE_ORDER_ENDPOINT = '/?rest_route=/impera-proxy-payment/v1/paypal-redirect/create-order';
    private const ORDER_STATUS_ENDPOINT = '/?rest_route=/impera-proxy-payment/v1/paypal-redirect/order-status';

    private LoggerInterface $logger;

    private WcGatewaySettings $settings;

    public function __construct()
    {
        $this->logger = ImperaPPHContainer()->get(LoggerInterface::class);

        // The shared key is needed on paths that never see a gateway instance,
        // so the settings row is read here rather than handed in.
        $this->settings = ImperaPPHContainer()->get(WcGatewaySettings::class);
    }

    /**
     * @return array The shape WooCommerce expects back from process_payment().
     */
    public function process(WC_Order $order, WcGatewaySettings $settings): array
    {
        $proxyDomain = $settings->getProxyDomain();

        $this->assertProxyDomain($order, $proxyDomain);
        $this->assertSharedKey($order, $settings);

        $order->add_order_note(sprintf('Starting checkout with PayPal proxy %s', $proxyDomain));

        $dto = CreateOrderDto::fromWcOrder(
            order:     $order,
            settings:  $settings,
            returnUrl: $this->buildReturnUrl($order),
            cancelUrl: $this->buildCancelUrl($order)
        );

        $createResponse = $this->requestOrder($order, Utils::buildDomain($proxyDomain), $dto);

        $this->rememberOrder($order, $proxyDomain, $createResponse, $settings->getMode());

        $order->add_order_note(sprintf(
            'Start redirect to PayPal through proxy %s, PayPal Order ID: %s',
            $proxyDomain,
            (string) $createResponse->getPpOrderId()
        ));

        // PayPal must not learn which shop the customer came from.
        if (!headers_sent()) {
            header('Referrer-Policy: no-referrer');
        }

        return [
            'result'   => 'success',
            'redirect' => $createResponse->getRedirectUrl(),
        ];
    }

    /**
     * Server-to-server confirmation. The browser round trip proves nothing on
     * its own, so nothing is settled until the proxy has asked PayPal.
     *
     * @param bool $capture Whether an approved order should be collected now.
     *                      True when the customer has just come back; false for
     *                      a plain read.
     */
    public function fetchOrderStatus(WC_Order $order, string $ppOrderId, string $mode, bool $capture = true): PaymentResponse
    {
        $proxyDomain = (string) $order->get_meta(IMPERA_WC_ORDER_PPH_PROXY_DOMAIN);

        if ($proxyDomain === '') {
            $message = sprintf('Order %d has no PayPal proxy domain stored.', $order->get_id());

            $this->logger->error($message);

            return PaymentResponse::failed($ppOrderId, $order->get_id(), $message);
        }

        $dto = OrderStatusDto::create($ppOrderId, $order->get_id(), $mode, $capture);

        $response = $this->post(
            Utils::buildDomain($proxyDomain) . self::ORDER_STATUS_ENDPOINT,
            $dto->jsonSerialize()
        );

        if (is_wp_error($response)) {
            $message = 'PayPal order status request error: ' . $response->get_error_message();

            $order->add_order_note($message);
            $this->logger->error($message);

            return PaymentResponse::failed($ppOrderId, $order->get_id(), $message);
        }

        return PaymentResponse::fromArray(Utils::getResponseJson($response));
    }

    private function requestOrder(WC_Order $order, string $proxyUrl, CreateOrderDto $dto): CreateOrderResponse
    {
        $response = $this->post($proxyUrl . self::CREATE_ORDER_ENDPOINT, $dto->jsonSerialize());

        if (is_wp_error($response)) {
            $message = 'PayPal create order request error: ' . $response->get_error_message();

            $order->add_order_note($message);
            $this->logger->error($message);

            throw new PaymentProcessException(
                'We cannot process your payment right now, please try another payment method.',
                'RE'
            );
        }

        $createResponse = CreateOrderResponse::fromArray(Utils::getResponseJson($response));

        if (!$createResponse->isSuccess()) {
            $message = sprintf(
                'PayPal Create Order ERROR by proxy %s, code: %s, message: %s',
                $proxyUrl,
                (string) $createResponse->getErrorCode(),
                (string) $createResponse->getErrorMessage()
            );

            $order->add_order_note($message);
            $this->logger->error($message);

            throw new PaymentProcessException(
                'We cannot process your payment right now, please try another payment method.',
                'COE'
            );
        }

        return $createResponse;
    }

    /**
     * What the order has to remember to be able to finish, or refund, later.
     */
    private function rememberOrder(
        WC_Order $order,
        string $proxyDomain,
        CreateOrderResponse $createResponse,
        string $mode
    ): void {
        $order->update_meta_data(IMPERA_WC_ORDER_PPH_PROXY_DOMAIN, $proxyDomain);
        $order->update_meta_data(IMPERA_WC_ORDER_PPH_ORDER_ID, (string) $createResponse->getPpOrderId());
        $order->update_meta_data(IMPERA_WC_ORDER_PPH_MODE, $mode);
        $order->update_meta_data(IMPERA_WC_ORDER_PPH_CURRENCY, $order->get_currency());

        if ($createResponse->getApproveUrl()) {
            $order->update_meta_data(IMPERA_WC_ORDER_PPH_APPROVE_URL, $createResponse->getApproveUrl());
        }

        $order->save_meta_data();
    }

    private function assertProxyDomain(WC_Order $order, string $proxyDomain): void
    {
        if ($proxyDomain !== '') {
            return;
        }

        $message = 'No PayPal proxy domain is configured for this gateway.';

        $order->add_order_note($message);
        $this->logger->error($message);

        throw new PaymentProcessException(
            'We cannot process your payment right now, please try another payment method.',
            'NPD'
        );
    }

    /**
     * Where the proxy sends the customer back to. It lives on the shop's home
     * URL with a flag rather than on a page, so no page has to exist for it, and
     * it carries the order key so the listener can prove whose order it is.
     */
    private function buildReturnUrl(WC_Order $order): string
    {
        return add_query_arg([
            IMPERA_PPH_RETURN_FLAG => '1',
            'wc_order_id'          => $order->get_id(),
            'order_key'            => $order->get_order_key(),
        ], site_url('/'));
    }

    private function buildCancelUrl(WC_Order $order): string
    {
        return add_query_arg([
            IMPERA_PPH_CANCEL_FLAG => '1',
            'wc_order_id'          => $order->get_id(),
            'order_key'            => $order->get_order_key(),
        ], site_url('/'));
    }

    /**
     * Nothing can be signed without the shared key, and the proxy would refuse
     * an unsigned request anyway - so the checkout stops here, where the reason
     * can still be told to somebody.
     */
    private function assertSharedKey(WC_Order $order, WcGatewaySettings $settings): void
    {
        if ($settings->getSharedKey() !== '') {
            return;
        }

        $this->logger->error(sprintf(
            'Order %d cannot be paid: the PayPal gateway has no shared key configured.',
            $order->get_id()
        ));

        throw new PaymentProcessException('PayPal is not configured on this store. Please choose another payment method.');
    }

    private function post(string $url, array $payload): mixed
    {
        // The proxy verifies the signature over the raw body, so the bytes that
        // are signed and the bytes that are sent have to be the same ones.
        $body = (string) wp_json_encode($payload);

        $sharedKey = $this->settings->getSharedKey();

        // A WP_Error rather than an exception: every caller already handles one,
        // and a status read must not turn fatal over a missing setting.
        if ($sharedKey === '') {
            return new WP_Error(
                'impera_pph_missing_shared_key',
                'The PayPal gateway has no shared key configured.'
            );
        }

        return wp_remote_post($url, [
            'sslverify' => true,
            'timeout'   => 45,
            'headers'   => [
                'Content-Type' => 'application/json',
                'X-Signature'  => hash_hmac('sha256', $body, $sharedKey),
            ],
            'body' => $body,
        ]);
    }
}
