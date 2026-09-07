<?php
/*
 * Plugin Name: Impera Gateway PayPal (Headless)
 * Plugin URI:
 * Description: PayPal redirect checkout for WooCommerce, paid through the Impera proxy. The shop never holds PayPal credentials; orders are settled by a signed server-to-server webhook.
 * Author: Impera
 * Author URI: https://imperafashion.com
 * Version: 1.0.0
 *
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */

if (!defined('ABSPATH')) {
    exit;
}

if ( !in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}

$autoloader = plugin_dir_path(__FILE__) . 'vendor/autoload.php';

if (file_exists($autoloader)) {
    require_once $autoloader;
} else {
    // Display a notification in the admin panel if the autoloader was forgotten to collect
    add_action('admin_notices', function() {
        echo '<div class="error"><p>Alert: Run <code>composer install</code> in the plugin folder.</p></div>';
    });
    return;
}

if ( WP_DEBUG ) {
    error_reporting( E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED );
}

//Config
require_once(plugin_dir_path(__FILE__) . 'bootstrap.php');

if ( ! defined( 'GITHUB_ACCESS_TOKEN' ) || empty( GITHUB_ACCESS_TOKEN ) ) {
    add_action( 'admin_notices', static function () {
        ?>
        <div class="notice notice-error">
            <p>
                <strong>Impera Gateway PayPal (Headless) Plugin:</strong>
                The <code>GITHUB_ACCESS_TOKEN</code> constant is not defined.
                GitHub updates and private repository access will not work until the token is configured.
            </p>
        </div>
        <?php
    } );
}

use ImperaPayPalHl\Core\CoreSetupPlugin;
use ImperaPayPalHl\Core\ImperaPPHMain;

/**
 * HPOS: every piece of order data this plugin writes goes through
 * $order->update_meta_data(), so the custom order tables are supported.
 * Declared here rather than in a provider because the check runs before
 * plugins_loaded, which is where the providers boot.
 */
add_action('before_woocommerce_init', static function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * REGISTER ACTIVATION HOOKS
 */
$coreSetup = new CoreSetupPlugin();
register_activation_hook(__FILE__, [$coreSetup, 'activation']);
register_deactivation_hook(__FILE__, [$coreSetup, 'deactivation']);
register_uninstall_hook(__FILE__, [CoreSetupPlugin::class, 'uninstall']);

/**
 * RUNNING THE PLUGIN
 */
ImperaPPHMain::getInstance()->registerProviders()->boot();
