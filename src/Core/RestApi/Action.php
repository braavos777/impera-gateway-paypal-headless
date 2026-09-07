<?php

declare(strict_types=1);

namespace ImperaPayPalHl\Core\RestApi;

use WP_REST_Request;

class Action
{
    private string $url;
    private array  $data = [
        'methods'             => '',
        'callback'            => '',
        'permission_callback' => '__return_true'
    ];

    private array $middleware = [];
    private array $permissionMiddleware = [];

    private function __construct(string $method, string $url, callable $callback)
    {
        $this->url = $url;
        $this->data['methods'] = $method;
        $this->data['callback'] = $callback;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public static function get(string $url, callable $callback): self
    {
        return new self('GET', $url, $callback);
    }

    public static function post(string $url, callable $callback): self
    {
        return new self('POST', $url, $callback);
    }

    public static function put(string $url, callable $callback): self
    {
        return new self('PUT', $url, $callback);
    }

    public static function delete(string $url, callable $callback): self
    {
        return new self('DELETE', $url, $callback);
    }

    public function addPermissionCallback(callable $callback): self
    {
        $this->data['permission_callback'] = $callback;
        return $this;
    }

    // Middleware for callback
    public function middleware(callable $fn): self
    {
        $this->middleware[] = $fn;
        return $this;
    }

    public function useMiddlewares(array $middlewares): self
    {
        foreach ($middlewares as $middleware) {
            $this->middleware($middleware);
        }

        return $this;
    }

    // Middleware for permission_callback
    public function permissionMiddleware(callable $fn): self
    {
        $this->permissionMiddleware[] = $fn;
        return $this;
    }

    private function buildMiddleware(callable $initialCallback, array $middleware): callable
    {
        $final = $initialCallback;

        foreach (array_reverse($middleware) as $mw) {
            $next = $final;
            $final = function(WP_REST_Request $request) use ($mw, $next) {
                return $mw($request, $next);
            };
        }

        return $final;
    }

    private function buildCallback(): callable
    {
        return $this->buildMiddleware($this->data['callback'], $this->middleware);
    }

    private function buildPermission(): callable
    {
        $initial = $this->data['permission_callback'] === '__return_true'
            ? fn($req) => true
            : $this->data['permission_callback'];

        return $this->buildMiddleware($initial, $this->permissionMiddleware);
    }

    public function toArray(): array
    {
        return [
            'methods'             => $this->data['methods'],
            'callback'            => $this->buildCallback(),
            'permission_callback' => $this->buildPermission(),
        ];
    }
}
