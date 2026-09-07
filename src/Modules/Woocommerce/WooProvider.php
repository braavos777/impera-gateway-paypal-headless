<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\Woocommerce;

use ImperaPayPalHl\Core\Container;
use ImperaPayPalHl\Core\Contract\ProviderInterface;
use ImperaPayPalHl\Modules\Woocommerce\Hooks\OrdersTable;

class WooProvider implements ProviderInterface
{
    public function register(Container $container): void
    {
    }

    public function boot(Container $container): void
    {
        //Add columns to orders table
        new OrdersTable();
    }
}
