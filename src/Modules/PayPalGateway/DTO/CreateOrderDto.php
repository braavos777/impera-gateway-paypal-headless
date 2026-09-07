<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\PayPalGateway\DTO;

use ImperaPayPalHl\Core\Support\Utils;
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
            orderInvoice:       $settings->getInvoicePrefix() . $order->get_order_number(),
            merchantSite:       Utils::homeDomain(),
            amount:             self::formatAmount($order->get_total()),
            currency:           $order->get_currency(),
            brandName:          $settings->getBrandName(),
            softDescriptor:     $settings->getSoftDescriptor(),
            description:        self::buildDescription($order, $settings),
            items:              self::buildItems($order, $settings),
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
     * The one line item the order is charged as. Its value has to be formatted
     * exactly like the amount the proxy sends alongside it - PayPal compares
     * item_total against the total to the cent, and "10.5" against "10.50" is
     * a 422.
     */
    private static function buildItems(WC_Order $order, WcGatewaySettings $settings): array
    {
        return [
            [
                'name'        => self::buildProductName($order, $settings),
                'description' => self::buildProductNames($order, $settings),
                'unit_amount' => [
                    'currency_code' => $order->get_currency(),
                    'value'         => self::formatAmount($order->get_total()),
                ],
                'quantity'    => '1',
        ]
        ];
    }

    /**
     * The line item title, built from the shop's title template.
     * PayPal allows 127 characters on an item name.
     */
    private static function buildProductName(WC_Order $order, WcGatewaySettings $settings): string
    {
        $firstItemName = '';

        foreach ($order->get_items() as $item) {
            $firstItemName = $item->get_name();
            break;
        }

        $title = trim(Utils::applyTitleShortcodes(
            $settings->getProductTitleSetting(),
            $firstItemName,
            $order->get_id()
        ));

        if ($title === '') {
            $title = sprintf('Order #%d', $order->get_id());
        }

        return mb_substr($title, 0, 127);
    }

    /**
     * Human readable contents of the cart, used as the line item description.
     * PayPal allows 2048 characters there.
     */
    private static function buildProductNames(WC_Order $order, WcGatewaySettings $settings): string
    {
        $parts = [];

        foreach ($order->get_items() as $item) {
            $name = Utils::applyTitleShortcodes(
                $settings->getProductTitleSetting(),
                $item->get_name(),
                $order->get_id()
            );
            $parts[] = $name . ' x ' . $item->get_quantity();
        }

        return mb_substr(trim(implode(', ', $parts)), 0, 2048);
    }

    /**
     * What the buyer reads on the PayPal page: the same cart list, cut to the
     * 127 characters PayPal allows on a purchase unit description.
     */
    private static function buildDescription(WC_Order $order, WcGatewaySettings $settings): string
    {
        return mb_substr(self::buildProductNames($order, $settings), 0, 127);
    }

    /**
     * PayPal wants the currency's own precision - two decimals for USD, none
     * for JPY - and refuses anything else.
     */
    private static function formatAmount(mixed $value): string
    {
        return (string) wc_format_decimal($value, wc_get_price_decimals());
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
