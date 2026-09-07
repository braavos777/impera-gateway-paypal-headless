<?php

declare(strict_types=1);

namespace ImperaPayPalHl\Core;

use RuntimeException;
use Closure;

final class Container {

    /**
     * Singleton instances.
     */
    private array $instances = [];

    /**
     * Registered bindings.
     */
    private array $bindings = [];

    private array $aliases = [];


    /**
     * Register singleton service.
     */
    public function singleton(string $id, callable $factory, ?string $alias = null): self
    {

        $this->bindings[$id] = [
            'factory' => Closure::fromCallable($factory),
            'singleton' => true,
        ];

        $this->bindAlias($id, $alias);

        return $this;
    }

    /**
     * Register transient service.
     */
    public function bind(string $id, callable $factory, ?string $alias = null): self
    {

        $this->bindings[$id] = [
            'factory' => Closure::fromCallable($factory),
            'singleton' => false,
        ];

        $this->bindAlias($id, $alias);

        return $this;
    }

    /**
     * Resolve service.
     */
    public function get(string $id): mixed
    {

        $id = $this->maybeAlias($id);

        /**
         * Return cached singleton.
         */
        if ($this->hasInstance($id)) {
            return $this->instances[$id];
        }

        /**
         * Service not registered.
         */
        if (!$this->hasBinding($id)) {

            if (!class_exists($id)) {

                if (interface_exists($id)) {
                    throw new RuntimeException("Interface '$id' is not bound.");
                }

                throw new RuntimeException(
                    "Class '$id' does not exist."
                );
            }

            /**
             * Fallback simple instantiation.
             */
            return $this->instances[$id] = new $id();
        }

        $binding = $this->bindings[$id];

        $object = $binding['factory']($this);

        /**
         * Cache singleton.
         */
        if ($binding['singleton']) {
            $this->instances[$id] = $object;
        }

        return $object;
    }

    /**
     * Create fresh instance.
     */
    public function make(string $id, ?callable $factory = null): mixed
    {
        $id = $this->maybeAlias($id);

        if (!$this->hasBinding($id)) {

            if (!class_exists($id)) {
                throw new RuntimeException("Class '$id' does not exist.");
            }

            if (is_callable($factory)) {
                return $factory($this);
            }

            return new $id();
        }

        return $this->bindings[$id]['factory']($this);
    }

    /**
     * Check binding/instance existence.
     */
    public function has(string $id): bool
    {
        return $this->hasBinding($id) || $this->hasInstance($id);
    }

    /**
     * Check instance existence.
     */
    public function hasBinding(string $id): bool
    {
        return isset($this->bindings[$id]);
    }


    /**
     * Check instance existence.
     */
    public function hasInstance(string $id): bool
    {
        return isset($this->instances[$id]);
    }

    /**
     * Remove singleton instance.
     */
    public function forget(string $id): void
    {
        unset($this->instances[$id]);
    }

    /**
     * Flush container.
     */
    public function flush(): void
    {
        $this->instances = [];
        $this->bindings = [];
        $this->aliases = [];
    }


    private function bindAlias(string $id, ?string $alias): void
    {
        if(empty($alias))
            return;

        if (isset($this->aliases[$alias])) {
            throw new RuntimeException("Alias '$alias' already exists.");
        }

        $this->aliases[$alias] = $id;
    }

    private function maybeAlias(string $id)
    {
        return isset($this->aliases[$id]) ? $this->aliases[$id] : $id;
    }
}
