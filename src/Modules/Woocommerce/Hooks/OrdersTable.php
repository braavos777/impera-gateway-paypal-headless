<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\Woocommerce\Hooks;

use WC_Order;

/**
 * Which proxy took the money, and what the buyer paid with. Worth a column of
 * its own: with several proxies in rotation across the estate, "which one was
 * this?" is the first question every reconciliation starts with.
 */
class OrdersTable
{
    private const COLUMN = 'impera_pph_proxy_source';

    public function __construct()
    {
        add_filter('woocommerce_shop_order_list_table_columns', [$this, 'addColumn']);

        add_action('woocommerce_shop_order_list_table_custom_column', [$this, 'addColumnContent'], 10, 2);
    }

    public function addColumn(array $columns): array
    {
        $new = [];

        foreach ($columns as $key => $label) {
            $new[$key] = $label;

            if ($key === 'order_status') {
                $new[self::COLUMN] = 'PayPal Proxy/Payment Source';
            }
        }

        return $new;
    }

    public function addColumnContent(string $column, WC_Order $order): void
    {
        if ($column !== self::COLUMN) {
            return;
        }

        $content = (string) $order->get_meta(IMPERA_WC_ORDER_PPH_PROXY_DOMAIN);

        $paymentSource = (string) $order->get_meta(IMPERA_WC_ORDER_PPH_PAYMENT_SOURCE);

        if ($paymentSource !== '') {
            $content .= sprintf('/%s', $paymentSource);
        }

        echo esc_html($content);
    }
}
