<?php

declare(strict_types=1);

namespace ImperaPayPalHl\Core\RestApi\Contract;

use WP_REST_Request;

interface RouteInterface
{
    public function getActions(): array;

    public function permissionCallback(WP_REST_Request $request): bool;
}
