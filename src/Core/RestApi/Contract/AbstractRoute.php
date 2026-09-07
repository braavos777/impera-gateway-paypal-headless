<?php

declare(strict_types=1);

namespace ImperaPayPalHl\Core\RestApi\Contract;


use ImperaPayPalHl\Core\RestApi\Action;
use WP_REST_Request;

abstract class AbstractRoute implements RouteInterface
{

    /**
     *
     * @return array|Action[]
     */
    abstract public function defineActions(): array;

    /**
     *
     * @return array|callable[]
     */
    public function globalMiddleware(): array
    {
        return [];
    }

    /**
     *
     * @return array|callable[]
     */
    public function globalPermissionMiddleware(): array
    {
        return [
            function($request, $next) {
                $nonce = $request->get_header('X-WP-Nonce') ?: '';

                if (!wp_verify_nonce($nonce, 'wp_rest')) {
                    return false; // 403 forbidden
                }
                return $next($request);
            }
        ];
    }

    /**
     * Default permissions
     */
    abstract public function permissionCallback(WP_REST_Request $request): bool;

    /**
     * Default permission_callback,
     */
    public function getActions(): array
    {
        return array_map(function (Action $action) {

            $action->addPermissionCallback([$this, 'permissionCallback']);

            //Add global route middleware if isset
            foreach ($this->globalMiddleware() as $mw) {
                $action->middleware($mw);
            }

            foreach ($this->globalPermissionMiddleware() as $pmw) {
                $action->permissionMiddleware($pmw);
            }


            return $action;

        }, $this->defineActions());
    }


}
