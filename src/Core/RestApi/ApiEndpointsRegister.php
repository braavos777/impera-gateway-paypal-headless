<?php

declare(strict_types=1);

namespace ImperaPayPalHl\Core\RestApi;

use RuntimeException;
use ImperaPayPalHl\Core\RestApi\Contract\RouteInterface;

/**
 * Abstract Class ApiEndpointsRegister
 *
 * Handles the automated registration of WordPress REST API routes based on provided route classes.
 */
abstract class ApiEndpointsRegister
{

    public function __construct()
    {
        $this->register();
    }

    /**
     * Returns an array of fully qualified class names that implement RouteInterface.
     *
     * @return string[] List of route class names.
     */
    abstract protected function getRoutes(): array;

    /**
     * Initializes the REST API registration by hooking into WordPress core.
     *
     * @return void
     */
    public function register(): void
    {
        if (empty($this->getRoutes())) {
            return;
        }

        // Hooks into the WordPress REST API initialization cycle
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * Iterates through all defined routes and registers them within the WordPress environment.
     *
     * @return void
     * @throws RuntimeException If a route class does not exist or is invalid.
     */
    public function registerRoutes(): void
    {

        foreach ($this->getRoutes() as $routeClass) {
            $route = $this->getRoute($routeClass);

            // Group actions by their URLs to prevent overwriting endpoints that share the same path
            // WordPress register_rest_route expects an array of endpoint arguments for a single route
            foreach ($route->getActions() as $action) {
                $this->registerRouteAction($action);
            }
        }
    }

    /**
     * Registers a single action component into the WordPress REST framework.
     *
     * @param Action $action
     * @return void
     */
    protected function registerRouteAction(Action $action): void
    {

        // IMPERA_PPH_REST_BASE_URL should be your namespace (e.g., 'impera-gateway-paypal-headless/v1')
        register_rest_route(
            IMPERA_PPH_REST_BASE_URL,
            $action->getUrl(),
            $action->toArray()
        );
    }

    /**
     * Resolves and instantiates the route class dynamically.
     *
     * @param string $routeClass Fully qualified class name.
     * @return RouteInterface
     * @throws RuntimeException If the class is missing or does not implement RouteInterface.
     */
    protected function getRoute(string $routeClass): RouteInterface
    {
        if (!class_exists($routeClass)) {
            throw new RuntimeException(sprintf('Route class "%s" was not found.', $routeClass));
        }

        $routeInstance = new $routeClass();

        if (!$routeInstance instanceof RouteInterface) {
            throw new RuntimeException(sprintf('Class "%s" must implement RouteInterface.', $routeClass));
        }

        return $routeInstance;
    }
}
