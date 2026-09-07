<?php

declare(strict_types=1);

namespace ImperaPayPalHl\Core;

use ImperaPayPalHl\Core\Contract\ProviderInterface;
use ImperaPayPalHl\Modules\PayPalGateway\PayPalGatewayProvider;
use ImperaPayPalHl\Modules\Woocommerce\WooProvider;
use RuntimeException;

final class ImperaPPHMain
{
    private static ?self $instance = null;

    private Container $container;

    /**
     * Prevent double boot.
     */
    private bool $booted = false;

    /**
     * Registered provider instances.
     */
    private array $registeredProviders = [];

    private const PROVIDERS = [
        CoreProvider::class,
        WooProvider::class,
        PayPalGatewayProvider::class,
    ];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->container = new Container();
    }

    /**
     * Register all providers.
     */
    public function registerProviders(): self
    {
        foreach (self::PROVIDERS as $provider) {
            if (!class_exists($provider)) {
                throw new RuntimeException("Provider '$provider' not found.");
            }

            $this->registerProvider(new $provider());
        }

        return $this;
    }

    private function registerProvider(ProviderInterface $provider): void
    {
        $provider->register($this->container);

        $this->registeredProviders[] = $provider;
    }

    /**
     * Boot application.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->registeredProviders as $provider) {
            $provider->boot($this->container);
        }

        $this->booted = true;
    }

    public function container(): Container
    {
        return $this->container;
    }
}
