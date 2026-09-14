<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\PayPalGateway\RestApi;

use ImperaPayPalHl\Core\RestApi\ApiEndpointsRegister;

final class RouteRegister extends ApiEndpointsRegister
{
    protected function getRoutes(): array
    {
        return [
            PaymentUpdateRoute::class,
            HeadlessRoute::class,
        ];
    }
}
