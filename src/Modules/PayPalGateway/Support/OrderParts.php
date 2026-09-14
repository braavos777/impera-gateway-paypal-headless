<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\PayPalGateway\Support;

use ImperaPayPalHl\Core\Support\Utils;
use ImperaPayPalHl\Modules\PayPalGateway\WcGatewaySettings;
use WC_Order;

/**
 * How a WooCommerce order is described to the proxy.
 *
 * Both flows have to describe it the same way, for different reasons. The
 * redirect flow sends this at creation, so it is what the buyer is shown; the
 * iframe flow sends it at capture, so it is what the captured amount is checked
 * against. If the two disagreed by a penny, every iframe payment would look
 * like tampering.
 *
 * The real cart is never itemised. PayPal requires
 * item_total + tax + shipping - discount to equal the total to the cent, and a
 * WooCommerce total with coupons applied practically never does - the result is
 * a 422 on an order that is perfectly valid. So the whole order travels as a
 * single item worth the order total, with the cart listed in its description;
 * item_total then trivially equals the amount.
 */
final class OrderParts
{
    /**
     * PayPal wants the currency's own precision - two decimals for USD, none
     * for JPY - and refuses anything else.
     */
    public static function amount(mixed $value): string
    {
        return (string) wc_format_decimal($value, wc_get_price_decimals());
    }

    public static function invoiceId(WC_Order $order, WcGatewaySettings $settings): string
    {
        return $settings->getInvoicePrefix() . $order->get_order_number();
    }

    /**
     * The one line item the order is charged as. Its value has to be formatted
     * exactly like the amount sent alongside it - PayPal compares item_total
     * against the total to the cent, and "10.5" against "10.50" is a 422.
     */
    public static function items(WC_Order $order, WcGatewaySettings $settings): array
    {
        return [
            [
                'name'        => self::productName($order, $settings),
                'description' => self::productNames($order, $settings),
                'unit_amount' => [
                    'currency_code' => $order->get_currency(),
                    'value'         => self::amount($order->get_total()),
                ],
                'quantity'    => '1',
            ],
        ];
    }

    /**
     * What the buyer reads on the PayPal page: the cart list, cut to the 127
     * characters PayPal allows on a purchase unit description.
     */
    public static function description(WC_Order $order, WcGatewaySettings $settings): string
    {
        return mb_substr(self::productNames($order, $settings), 0, 127);
    }

    /**
     * The line item title, built from the shop's title template.
     * PayPal allows 127 characters on an item name.
     */
    public static function productName(WC_Order $order, WcGatewaySettings $settings): string
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
    public static function productNames(WC_Order $order, WcGatewaySettings $settings): string
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
}
