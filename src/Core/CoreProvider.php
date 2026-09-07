<?php

declare(strict_types=1);

namespace ImperaPayPalHl\Core;

use ImperaPayPalHl\Core\Contract\LoggerInterface;
use ImperaPayPalHl\Core\Contract\ProviderInterface;
use ImperaPayPalHl\Core\Support\WcLogger;
use ImperaPayPalHl\Core\UpdateChecker\YahnisElstsChecker;

class CoreProvider implements ProviderInterface
{
    public function register(Container $container): void
    {
        $container->singleton(LoggerInterface::class, fn () => new WcLogger());
    }

    public function boot(Container $container): void
    {
        //Check Updates from GITHUB
        new YahnisElstsChecker();
    }
}
