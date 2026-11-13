<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\PayPalGateway;

use ImperaPayPalHl\Core\Container;
use ImperaPayPalHl\Core\Contract\ProviderInterface;
use ImperaPayPalHl\Modules\PayPalGateway\Hooks\Actions\MissingKeyNotice;
use ImperaPayPalHl\Modules\PayPalGateway\Hooks\Actions\ReturnListener;
use ImperaPayPalHl\Modules\PayPalGateway\RestApi\RouteRegister;
use ImperaPayPalHl\Modules\PayPalGateway\UseCases\OrderCompletionService;
use ImperaPayPalHl\Modules\PayPalGateway\UseCases\PaymentProcessor;
use ImperaPayPalHl\Modules\PayPalGateway\UseCases\PaymentReconciler;
use ImperaPayPalHl\Core\Contract\LoggerInterface;
use ImperaPayPalHl\Modules\PayPalGateway\UseCases\RefundProcessor;
use ImperaPayPalHl\Modules\PayPalGateway\UseCases\ReturnProcessor;

class PayPalGatewayProvider implements ProviderInterface
{
    public function register(Container $container): void
    {
        $container->singleton(
            WcGatewaySettings::class,
            fn (Container $container) => new WcGatewaySettings(IMPERA_PPH_GATEWAY_ID)
        );

        $container->singleton(PaymentProcessor::class, fn (Container $container) => new PaymentProcessor());

        $container->singleton(OrderCompletionService::class, fn (Container $container) => new OrderCompletionService());

        $container->singleton(PaymentReconciler::class, fn (Container $container) => new PaymentReconciler(
            $container->get(PaymentProcessor::class),
            $container->get(OrderCompletionService::class),
            $container->get(WcGatewaySettings::class),
            $container->get(LoggerInterface::class)
        ));

        // Transient on purpose: a refund is a one-shot admin action, and holding
        // its state across a request that may refund twice buys nothing.
        $container->bind(RefundProcessor::class, fn (Container $container) => new RefundProcessor());

        $container->bind(
            ReturnProcessor::class,
            fn (Container $container) => new ReturnProcessor(
                $container->get(PaymentProcessor::class),
                $container->get(OrderCompletionService::class),
                $container->get(WcGatewaySettings::class)
            )
        );
    }

    public function boot(Container $container): void
    {
        add_filter('woocommerce_payment_gateways', [$this, 'registerGateway']);

        // The customer coming back from PayPal through the proxy.
        new ReturnListener();

        // Says out loud why an enabled gateway is refusing to show itself.
        new MissingKeyNotice();

        // The proxy's server-to-server push, which settles an order whose
        // customer never came back at all.
        new RouteRegister();

        // Settles payments whose outcome was not known (PaymentReconciler).
        PaymentReconciler::register();

    }

    public function registerGateway(array $gateways): array
    {
        $gateways[] = PayPalGateway::class;

        return $gateways;
    }

}
