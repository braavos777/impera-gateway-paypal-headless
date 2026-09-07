<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\PayPalGateway;

use ImperaPayPalHl\Core\Contract\LoggerInterface;
use ImperaPayPalHl\Modules\PayPalGateway\Exceptions\PaymentProcessException;
use ImperaPayPalHl\Modules\PayPalGateway\Exceptions\RefundProcessException;
use ImperaPayPalHl\Modules\PayPalGateway\UseCases\PaymentProcessor;
use ImperaPayPalHl\Modules\PayPalGateway\UseCases\RefundProcessor;
use Throwable;
use WC_Payment_Gateway;
use WP_Error;

/**
 * An ordinary WooCommerce redirect gateway with a fixed id.
 *
 * It renders no HTML of its own and hooks no checkout output, which is what lets
 * the same gateway serve the classic checkout, the Blocks checkout's redirect
 * result and a pay-for-order page without a variant for each.
 */
class PayPalGateway extends WC_Payment_Gateway
{
    public array $config = [];

    public WcGatewaySettings $gatewaySettings;

    private LoggerInterface $logger;

    private PaymentProcessor $paymentProcessor;

    public function __construct()
    {
        $this->id = IMPERA_PPH_GATEWAY_ID;

        $this->gatewaySettings = ImperaPPHContainer()->get(WcGatewaySettings::class);

        $this->logger = ImperaPPHContainer()->get(LoggerInterface::class);

        $this->paymentProcessor = ImperaPPHContainer()->get(PaymentProcessor::class);

        $this->icon               = '';
        $this->has_fields         = false;
        $this->method_title       = 'Impera Gateway PayPal';
        $this->method_description = 'PayPal redirect checkout through the Impera proxy. The shop never holds PayPal credentials.';
        $this->order_button_text  = (string) $this->gatewaySettings->get('checkout_button_content', 'Continue to PayPal');

        $this->supports = [
            'products',
            'refunds',
        ];

        $this->config = require __DIR__ . '/config/paypal-gateway-config.php';

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = (string) $this->gatewaySettings->get('title', 'PayPal');
        $this->description = (string) $this->gatewaySettings->get('description', '');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    /**
     * The only payment path. The order is loaded, never created: this gateway is
     * always paying for an order WooCommerce already has.
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        $order->add_order_note('Start Process Payment with PayPal proxy');

        do_action('impera_pph_gateway_process_payment_start', $order);

        try {
            return $this->paymentProcessor->process($order, $this->gatewaySettings);
        } catch (PaymentProcessException $e) {
            $message = $e->getErrorMessage();
        } catch (Throwable $e) {
            $order->add_order_note($e->getMessage());

            $this->logger->error($e->getMessage());

            $message = 'We cannot process your payment right now, please try another payment method.[E]';

            do_action('impera_pph_gateway_process_payment_error', $order, $e);
        }

        wc_add_notice($message, 'error');

        $order->update_status('failed');

        return [
            'result'   => 'failure',
            'redirect' => '',
            'reload'   => true, // This flag will force WooCommerce JS to refresh the page
        ];
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);

        /** @var RefundProcessor $refundProcessor */
        $refundProcessor = ImperaPPHContainer()->get(RefundProcessor::class);

        try {
            return $refundProcessor->process($order, $amount, $reason)->getRefundId();
        } catch (RefundProcessException $e) {
            return new WP_Error('paypal_refund_process_error', $e->getMessage());
        } catch (Throwable $e) {
            $order->add_order_note($e->getMessage());

            $this->logger->error($e->getMessage());

            return new WP_Error('paypal_refund_unknown_error', $e->getMessage());
        }
    }

    public function init_form_fields()
    {
        $this->form_fields = apply_filters(
            'woocommerce_' . $this->id . '_form_fields',
            $this->config['payment_fields']
        );
    }

    /**
     * Hidden rather than broken: a shop selling in a currency PayPal will not
     * take, or one that has not been told which proxy to pay through or which
     * key to sign with, would only fail at the checkout's last step.
     */
    public function is_available()
    {
        if (!parent::is_available()) {
            return false;
        }

        if ($this->gatewaySettings->getProxyDomain() === '') {
            return false;
        }

        if ($this->gatewaySettings->getSharedKey() === '') {
            return false;
        }

        return $this->is_valid_for_use();
    }

    public function get_icon()
    {
        $icon = '<svg class="impera-pph-payment-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#003087" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 20 8.5 4h5.2A3.8 3.8 0 0 1 17 8.6a4.6 4.6 0 0 1-4.6 4.1H9.2"/><path d="M9.6 20 10.4 15h2.3a4.6 4.6 0 0 0 4.5-4"/></svg>';

        return apply_filters('woocommerce_gateway_icon', '<span class="impera-pph-payment-icons">' . $icon . '</span>', $this->id);
    }

    private function is_valid_for_use(): bool
    {
        return in_array(
            get_woocommerce_currency(),
            apply_filters('woocommerce_paypal_headless_supported_currencies', $this->config['available_currency']),
            true
        );
    }
}
