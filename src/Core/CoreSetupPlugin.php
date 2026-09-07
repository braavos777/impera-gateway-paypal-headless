<?php

declare(strict_types=1);

namespace ImperaPayPalHl\Core;

/**
 * This gateway keeps no tables and no options of its own - the WooCommerce
 * settings API owns everything it stores - so activation has nothing to build
 * and uninstall has nothing to clean up beyond the order meta, which belongs to
 * the orders and must outlive the plugin.
 */
class CoreSetupPlugin
{
    public function activation(): void
    {
    }

    public function deactivation(): void
    {
    }

    public static function uninstall(): void
    {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            return;
        }
    }
}
