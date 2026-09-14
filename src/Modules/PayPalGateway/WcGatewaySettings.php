<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\PayPalGateway;

/**
 * Reads the gateway's own WooCommerce settings row without going through
 * WC_Payment_Gateway, so a use case can ask for a setting without a gateway
 * instance being constructed first.
 */
final class WcGatewaySettings
{
    private string $prefix = 'woocommerce_';

    private string $suffix = '_settings';

    private string $gatewayId;

    private array $settings = [];

    public function __construct(string $gatewayId)
    {
        $this->gatewayId = $gatewayId;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (empty($this->settings)) {
            $this->initOptions();
        }

        $value = $this->settings[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    /**
     * The proxy host this shop pays through. A plain setting rather than a
     * rotating pool: this gateway has one proxy, and the choice must not depend
     * on a WooCommerce session that a REST checkout does not have.
     */
    public function getProxyDomain(): string
    {
        return trim((string) $this->get('proxy_domain', ''));
    }

    /**
     * The secret the shop's requests are signed with, and the proxy's pushes
     * verified against. Kept in the settings row rather than in the plugin
     * files, which are public.
     *
     * A constant in wp-config.php wins: a secret placed there is a deployment
     * decision, and the admin screen has no business quietly replacing it.
     */
    public function getSharedKey(): string
    {
        if (defined('IMPERA_PPH_KEY') && trim((string) IMPERA_PPH_KEY) !== '') {
            return trim((string) IMPERA_PPH_KEY);
        }

        return trim((string) $this->get('shared_key', ''));
    }

    /**
     * Sandbox is a stored setting, never a query parameter: a mode that can be
     * switched by editing the URL is a mode a customer can switch.
     */
    public function isSandbox(): bool
    {
        return $this->get('sandbox', 'no') === 'yes';
    }

    public function getMode(): string
    {
        return $this->isSandbox() ? 'sandbox' : 'production';
    }

    public function getInvoicePrefix(): string
    {
        return (string) $this->get('invoice_prefix', 'WC-');
    }

    public function getBrandName(): string
    {
        return (string) $this->get('brand_name', get_bloginfo('name'));
    }

    public function getSoftDescriptor(): string
    {
        return (string) $this->get('soft_descriptor', '');
    }

    public function getProductTitleSetting(): string
    {
        return (string) $this->get('product_title_setting', '[keep_original]');
    }

    /**
     * Which checkout this gateway serves.
     *
     * 'redirect' sends the customer to PayPal and settles when they come back.
     * 'iframe' embeds the proxy's buttons in a storefront, and the payment is
     * already approved by the time process_payment() runs.
     */
    public function getCheckoutFlow(): string
    {
        return $this->get('checkout_flow', 'redirect') === 'iframe' ? 'iframe' : 'redirect';
    }

    public function isIframeFlow(): bool
    {
        return $this->getCheckoutFlow() === 'iframe';
    }

    /**
     * The origin of the page that frames the buttons.
     *
     * It is handed to the proxy, which makes it the postMessage target of the
     * bridge and a frame-ancestors source, so it travels whole - scheme and
     * port included. A bare host would never match: the bridge compares origins
     * literally.
     *
     * Falls back to this site, which is right for a classic WooCommerce
     * checkout and wrong for a headless one - hence the setting.
     */
    public function getStorefrontOrigin(): string
    {
        $origin = trim((string) $this->get('storefront_origin', ''));

        if ($origin === '') {
            $origin = (string) home_url();
        }

        $parts = wp_parse_url($origin);

        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return '';
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        $port    = isset($parts['port']) ? (int) $parts['port'] : 0;
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme . '://' . strtolower((string) $parts['host'])
            . ($port > 0 && $port !== $default ? ':' . $port : '');
    }

    /**
     * NO_SHIPPING keeps PayPal from showing an address at all;
     * SET_PROVIDED_ADDRESS shows the one the shop already collected;
     * GET_FROM_FILE lets the buyer choose - and change the total with it.
     */
    public function getShippingPreference(): string
    {
        $preference = strtoupper((string) $this->get('shipping_preference', 'NO_SHIPPING'));

        return in_array($preference, ['NO_SHIPPING', 'SET_PROVIDED_ADDRESS', 'GET_FROM_FILE'], true)
            ? $preference
            : 'NO_SHIPPING';
    }

    /**
     * What a cancelled payment does to the order. Leaving it pending lets the
     * customer try again from My Account; cancelling it closes the order and
     * releases the stock.
     */
    public function cancelsOrderOnCancel(): bool
    {
        return $this->get('cancel_behaviour', 'pending') === 'cancelled';
    }

    private function getOptionKey(): string
    {
        return $this->prefix . $this->gatewayId . $this->suffix;
    }

    private function initOptions(): void
    {
        $settings = get_option($this->getOptionKey(), []);

        $this->settings = is_array($settings) ? $settings : [];
    }
}
