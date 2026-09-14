<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\PayPalGateway\DTO;

use ImperaPayPalHl\Core\Support\Utils;
use ImperaPayPalHl\Modules\PayPalGateway\Support\OrderParts;
use ImperaPayPalHl\Modules\PayPalGateway\WcGatewaySettings;
use JsonSerializable;
use WC_Order;

/**
 * Everything the proxy needs to build a PayPal Order v2. The proxy owns the
 * credentials; the shop never sees them.
 *
 * The real cart is never sent line by line. PayPal requires
 * item_total + tax + shipping - discount to equal the total to the cent, and a
 * WooCommerce total with coupons applied practically never does - the result is
 * a 422 on an order that is perfectly valid. So the whole order travels as a
 * single item worth the order total, with the cart listed in its description;
 * item_total then trivially equals the amount, and the proxy has to say so in
 * amount.breakdown.
 */
final class CreateOrderDto implements JsonSerializable
{
    private function __construct(
        public readonly int $wcOrderId,
        public readonly string $orderInvoice,
        public readonly string $merchantSite,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $brandName,
        public readonly string $softDescriptor,
        public readonly string $description,
        public readonly array $items,
        public readonly string $locale,
        public readonly string $shippingPreference,
        public readonly array $shipping,
        public readonly string $customerEmail,
        public readonly string $customerIp,
        public readonly string $returnUrl,
        public readonly string $cancelUrl,
        public readonly bool $sandbox,
    ) {}

    public static function fromWcOrder(
        WC_Order $order,
        WcGatewaySettings $settings,
        string $returnUrl,
        string $cancelUrl
    ): self {
        $shippingPreference = $settings->getShippingPreference();

        return new self(
            wcOrderId:          $order->get_id(),
            orderInvoice:       OrderParts::invoiceId($order, $settings),
            merchantSite:       Utils::homeDomain(),
            amount:             OrderParts::amount($order->get_total()),
            currency:           $order->get_currency(),
            brandName:          $settings->getBrandName(),
            softDescriptor:     $settings->getSoftDescriptor(),
            description:        OrderParts::description($order, $settings),
            items:              OrderParts::items($order, $settings),
            locale:             self::buildLocale(),
            shippingPreference: $shippingPreference,
            // Only worth sending when PayPal is going to be told to use it.
            shipping:           $shippingPreference === 'SET_PROVIDED_ADDRESS'
                                    ? self::buildShipping($order)
                                    : [],
            customerEmail:      (string) $order->get_billing_email(),
            customerIp:         (string) Utils::getRealIp(),
            returnUrl:          $returnUrl,
            cancelUrl:          $cancelUrl,
            sandbox:            $settings->isSandbox(),
        );
    }

    /**
     * Shipping falls back to billing whenever the order has no separate address:
     * SET_PROVIDED_ADDRESS with a half-filled address is a 422, and an order
     * that ships to the billing address is the ordinary case.
     */
    private static function buildShipping(WC_Order $order): array
    {
        $name = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());

        if ($name === '') {
            $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        }

        return [
            'name'        => $name,
            'line1'       => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
            'line2'       => $order->get_shipping_address_2() ?: $order->get_billing_address_2(),
            'city'        => $order->get_shipping_city() ?: $order->get_billing_city(),
            'state'       => $order->get_shipping_state() ?: $order->get_billing_state(),
            'postal_code' => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
            'country'     => $order->get_shipping_country() ?: $order->get_billing_country(),
        ];
    }

    /**
     * PayPal wants BCP-47 ("en-US"), not the bare two-letter code Stripe takes.
     * WordPress stores it with an underscore.
     */
    private static function buildLocale(): string
    {
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();

        return str_replace('_', '-', (string) $locale);
    }

    public function jsonSerialize(): array
    {
        return [
            'wc_order_id'         => $this->wcOrderId,
            'order_invoice'       => $this->orderInvoice,
            'merchant_site'       => $this->merchantSite,
            'amount'              => $this->amount,
            'currency'            => $this->currency,
            'brand_name'          => $this->brandName,
            'soft_descriptor'     => $this->softDescriptor,
            'description'         => $this->description,
            'items'               => $this->items,
            'locale'              => $this->locale,
            'shipping_preference' => $this->shippingPreference,
            'shipping'            => $this->shipping,
            'customer_email'      => $this->customerEmail,
            'customer_ip'         => $this->customerIp,
            'return_url'          => $this->returnUrl,
            'cancel_url'          => $this->cancelUrl,
            'sandbox'             => $this->sandbox,
        ];
    }
}
