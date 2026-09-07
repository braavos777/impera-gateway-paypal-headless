<?php

if (!defined('ABSPATH')) {
    exit;
}

define('IMPERA_PPH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('IMPERA_PPH_PLUGIN_URL', plugin_dir_url(__FILE__));
define('IMPERA_PPH_PLUGIN_NAME', 'impera-gateway-paypal-headless');
define('IMPERA_PPH_PLUGIN_VERSION', '1.0.0');

const IMPERA_PPH_REST_BASE_URL = IMPERA_PPH_PLUGIN_NAME . '/v1';

const IMPERA_PPH_GATEWAY_ID = 'impera_paypal_headless';


/**
 * Currencies PayPal will actually take. A currency outside this list makes the
 * gateway hide itself rather than fail at the proxy.
 */
define('IMPERA_PPH_AVAILABLE_CURRENCIES', [
    'AUD', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF',
    'ILS', 'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK', 'SGD',
    'THB', 'TWD', 'USD',
]);

/**
 * Query flags the proxy sends the customer back with. They live on the shop's
 * own home URL rather than on a page, so no page has to exist for them.
 */
const IMPERA_PPH_RETURN_FLAG = 'impera_pph_return';
const IMPERA_PPH_CANCEL_FLAG = 'impera_pph_cancel';

//WC Order META KEYS
const IMPERA_WC_ORDER_PPH_PROXY_DOMAIN   = '_impera_pph_proxy_domain';
const IMPERA_WC_ORDER_PPH_ORDER_ID       = '_impera_pph_paypal_order_id';
const IMPERA_WC_ORDER_PPH_CAPTURE_ID     = '_impera_pph_capture_id';
const IMPERA_WC_ORDER_PPH_REFUND_ID      = '_impera_pph_refund_id';
const IMPERA_WC_ORDER_PPH_REFUND_USER    = '_impera_pph_refund_user';
const IMPERA_WC_ORDER_PPH_GROSS_AMOUNT   = '_impera_pph_gross_amount';
const IMPERA_WC_ORDER_PPH_FEE            = '_impera_pph_fee';
const IMPERA_WC_ORDER_PPH_NET_AMOUNT     = '_impera_pph_net_amount';
const IMPERA_WC_ORDER_PPH_REFUNDED       = '_impera_pph_refunded_amount';
const IMPERA_WC_ORDER_PPH_CURRENCY       = '_impera_pph_currency';
const IMPERA_WC_ORDER_PPH_PAYMENT_SOURCE = '_impera_pph_payment_source';
const IMPERA_WC_ORDER_PPH_MODE           = '_impera_pph_mode';
const IMPERA_WC_ORDER_PPH_APPROVE_URL    = '_impera_pph_approve_url';

require_once(IMPERA_PPH_PLUGIN_DIR . 'functions.php');
