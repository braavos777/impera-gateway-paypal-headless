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
 * "Here is the order the customer just approved, here is what it is worth,
 * take the money."
 *
 * This is the only message in the embedded flow that carries a trustworthy
 * amount. The PayPal order was created by the customer's browser, from figures
 * the browser chose; nothing before this request has told the proxy what the
 * cart was actually worth. So order_parts.amount is not a convenience - it is
 * what the proxy compares the captured amount against before it will report the
 * order as paid, and a payment that does not match is refunded on the spot.
 *
 * Which means the total here has to be WooCommerce's own, read off the saved
 * order, and never anything that passed through the browser on its way.
 */
final class CaptureOrderDto implements JsonSerializable
{
    private function __construct(
        public readonly string $ppOrderId,
        public readonly int $wcOrderId,
        public readonly string $merchantSite,
        public readonly string $mode,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $invoiceId,
        public readonly array $items,
    ) {}

    public static function fromWcOrder(WC_Order $order, string $ppOrderId, WcGatewaySettings $settings): self
    {
        return new self(
            ppOrderId:    $ppOrderId,
            wcOrderId:    $order->get_id(),
            merchantSite: Utils::homeDomain(),
            mode:         $settings->getMode(),
            amount:       OrderParts::amount($order->get_total()),
            currency:     $order->get_currency(),
            invoiceId:    OrderParts::invoiceId($order, $settings),
            // Written onto the PayPal order by the proxy's PATCH, so the payment
            // can be read back from the PayPal dashboard as something more than
            // a bare total.
            items:        OrderParts::items($order, $settings),
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'pp_order_id'   => $this->ppOrderId,
            'wc_order_id'   => $this->wcOrderId,
            'merchant_site' => $this->merchantSite,
            'mode'          => $this->mode,
            'order_parts'   => [
                'amount'     => $this->amount,
                'currency'   => $this->currency,
                'invoice_id' => $this->invoiceId,
                'items'      => $this->items,
            ],
        ];
    }
}
