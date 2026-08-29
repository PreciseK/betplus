<?php

declare(strict_types=1);

namespace BlackRed\Bootstrap;

use Closure;
use RuntimeException;

/**
 * Minimal dependency injection container.
 *
 * Services are registered as factories (closures) and resolved on first
 * access. Resolved instances are cached for the request lifecycle.
 *
 * This is intentionally tiny — ~50 lines. It is NOT a full container (no
 * autowiring, no parameter injection). Every service is defined explicitly
 * in config/services.php so the wiring is auditable in one place.
 */
final class Container
{
    /** @var array<string, Closure> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, bool> */
    private array $resolving = [];

    /**
     * Register a service factory. The factory receives the container and
     * returns the service instance.
     */
    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]); // invalidate any cached instance
    }

    /**
     * Register an already-built instance. Useful for primitives and config.
     */
    public function instance(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }

    /**
     * Resolve a service. First call builds it; subsequent calls return the cached instance.
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new RuntimeException("Service not registered: {$id}");
        }

        if (isset($this->resolving[$id])) {
            throw new RuntimeException("Circular dependency detected resolving: {$id}");
        }

        $this->resolving[$id] = true;
        try {
            $instance = ($this->factories[$id])($this);
        } finally {
            unset($this->resolving[$id]);
        }

        $this->instances[$id] = $instance;
        return $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->instances);
    }
}
