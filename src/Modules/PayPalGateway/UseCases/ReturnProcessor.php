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
 * Handles the customer landing back on the shop after PayPal.
 *
 * The redirect parameters are signed, but a signature only proves the proxy sent
 * them - never that the money arrived. The order is settled solely on the
 * outcome of a server-to-server status call.
 */
final class ReturnProcessor
{
    private LoggerInterface $logger;

    public function __construct(
        private PaymentProcessor $paymentProcessor,
        private OrderCompletionService $orderCompletion,
        private WcGatewaySettings $settings,
    ) {
        $this->logger = ImperaPPHContainer()->get(LoggerInterface::class);
    }

    /**
     * The signature the proxy is expected to have produced. Mirrors
     * ReturnState::redirectSignature() on the proxy side.
     */
    private function signature(string $ppOrderId, int $wcOrderId, string $status, string $mode): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [$ppOrderId, $wcOrderId, $status, $mode]),
            $this->settings->getSharedKey()
        );
    }

    public function handleReturn(array $query): void
    {
        $order = $this->resolveOrder($query);

        if (!$order instanceof WC_Order) {
            do_action('impera_gateway_paypal_headless_return_order_not_found', $query);
            $this->redirectToThankYou();
        }

        // Already handled - a refresh or a double redirect must not charge twice.
        if ($order->is_paid()) {
            $this->logger->debug('PayPal return: order already paid, redirecting to thank you page.');
            $this->redirectToThankYou($order);
        }

        $ppOrderId = sanitize_text_field((string) ($query['pp_order_id'] ?? ''));
        $status    = sanitize_text_field((string) ($query['status'] ?? ''));
        $mode      = sanitize_text_field((string) ($query['mode'] ?? 'production'));
        $signature = (string) ($query['sig'] ?? '');

        if ($ppOrderId === '' || !$this->isValidSignature($ppOrderId, $order->get_id(), $status, $mode, $signature)) {
            $message = sprintf('PayPal return signature check failed for order %d', $order->get_id());

            $order->add_order_note($message);
            $this->logger->error($message);

            $this->failAndRedirect($order, 'We could not verify your payment, please contact us before trying again.');
        }

        $order->add_order_note(sprintf('Customer returned from PayPal, PayPal Order ID: %s', $ppOrderId));

        try {
            // The proxy captures an approved order as part of answering this, so
            // it is the same call that collects the money.
            $paymentResponse = $this->paymentProcessor->fetchOrderStatus($order, $ppOrderId, $mode, true);
            do_action('impera_gateway_paypal_headless_return_payment_status', $order, $paymentResponse, $query);
        } catch (Throwable $e) {
            $order->add_order_note($e->getMessage());
            $this->logger->error($e->getMessage());

            $this->failAndRedirect($order, 'We cannot confirm your payment right now, please contact us.');
        }

        $settled = $this->orderCompletion->complete($order, $paymentResponse, 'customer-return');

        if ($settled) {
            $this->clearCart();
            $this->logger->debug('PayPal return: order settled, redirecting to thank you page.');
            $this->redirectToThankYou($order);
        }

        // Not settled, but not necessarily lost either: a pending payment or a
        // declined instrument leaves an order that is still going somewhere.
        if ($paymentResponse->isPending()) {
            $this->clearCart();
            $this->logger->debug('PayPal return: order still pending, redirecting to thank you page.');
            $this->redirectToThankYou($order);
        }

        if ($paymentResponse->needsPayerAction()) {
            $this->logger->debug('PayPal return: order needs payer action, redirecting to thank you page.');
            $this->failAndRedirect(
                $order,
                'PayPal could not use that payment method. Please try again and choose another one.',
                false
            );
        }

        if ($paymentResponse->isAmountMismatch()) {
            $this->logger->debug('PayPal return: order amount mismatch, redirecting to thank you page.');
            $this->failAndRedirect(
                $order,
                'The amount PayPal charged does not match your order. We have been notified — please contact us before paying again.',
                false
            );
        }

        $this->failAndRedirect($order, 'Your payment was not completed, please try another payment method.', false);
    }

    public function handleCancel(array $query): void
    {
        $order = $this->resolveOrder($query);

        if ($order instanceof WC_Order && !$order->is_paid()) {
            $order->add_order_note('Customer cancelled the payment on the PayPal page.');

            // Left pending by default: the customer can pay the same order again
            // from My Account, and cancelling would release the stock behind
            // someone who simply changed their mind for a moment.
            if ($this->settings->cancelsOrderOnCancel() && !$order->has_status('cancelled')) {
                $order->update_status('cancelled');
            }
        }

        if (!$order instanceof WC_Order) {
            do_action('impera_gateway_paypal_headless_cancel_order_not_found', $query);
            wp_safe_redirect(wc_get_checkout_url());
            exit();
        }

        wc_add_notice('The payment was cancelled. Your order is still waiting for you.', 'notice');

        wp_safe_redirect($order->get_cancel_order_url());
        exit;
    }

    private function resolveOrder(array $query): ?WC_Order
    {
        $orderId  = absint($query['wc_order_id'] ?? 0);
        $orderKey = sanitize_text_field((string) ($query['order_key'] ?? ''));

        if ($orderId === 0 || $orderKey === '') {
            return null;
        }

        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order || !hash_equals($order->get_order_key(), $orderKey)) {
            $this->logger->error(sprintf('PayPal return: order %d not found or order key mismatch.', $orderId));

            return null;
        }

        return $order;
    }

    private function isValidSignature(string $ppOrderId, int $wcOrderId, string $status, string $mode, string $signature): bool
    {
        // Without a key nothing can be verified, and a return that cannot be
        // verified must never pass for one the proxy sent.
        if ($signature === '' || $this->settings->getSharedKey() === '') {
            return false;
        }

        return hash_equals($this->signature($ppOrderId, $wcOrderId, $status, $mode), $signature);
    }

    /**
     * @param bool $markFailed Whether the order itself should be failed. A
     *                         declined instrument leaves an order PayPal is
     *                         still willing to take money for, so it is not.
     */
    private function failAndRedirect(WC_Order $order, string $notice, bool $markFailed = true): never
    {

        do_action('impera_gateway_paypal_headless_return_fail_redirect', $order, $notice, $markFailed);

        if ($markFailed && !$order->has_status('failed')) {
            $order->update_status('failed');
        }

        wc_add_notice($notice, 'error');
        $this->logger->debug('ReturnProcessor::failAndRedirect failAndRedirect redirecting to thank you page.');
        $this->redirectToThankYou($order);
    }

    /**
     * Browser-only cleanup: there is no cart on the webhook path.
     */
    private function clearCart(): void
    {
        if (WC()->cart) {
            WC()->cart->empty_cart();
        }
    }

    /**
     *
     * @param WC_Order|null $order Order object.
     * @return string
     */
    private function redirectToThankYou($order = null): never
    {
        if ( $order ) {
            $return_url = $order->get_checkout_order_received_url();
        } else {
            $return_url = wc_get_endpoint_url( 'order-received', '', wc_get_checkout_url() );
        }

        /**
         * Filter the return url.
         *
         * @since 2.4.0
         * @param string $return_url Return URL.
         * @param WC_Order|null $order Order object.
         * @return string
         */
        $return_url = apply_filters( 'woocommerce_get_return_url', $return_url, $order );

        $this->logger->debug("ReturnProcessor::redirectToThankYou redirecting to thank you page. return_url: $return_url");

        wp_safe_redirect($return_url);
        exit;
    }
}
