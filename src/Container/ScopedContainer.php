<?php

declare(strict_types=1);

namespace Careminate\Container;

use Careminate\Contracts\Container\ScopedContainerInterface;
use Careminate\Exception\Container\ScopeException;

final class ScopedContainer implements ScopedContainerInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $instances = [];

    private bool $closed = false;

    public function __construct(
        private readonly Container $root,
        private readonly string $name,
    ) {
    }

    public function get(string $id): mixed
    {
        $this->assertOpen();

        return $this->root->resolveForScope($id, $this);
    }

    public function has(string $id): bool
    {
        $this->assertOpen();

        if (
            $id === self::class
            || $id === ScopedContainerInterface::class
        ) {
            return true;
        }

        return $this->root->has($id);
    }

    public function tagged(string $tag): iterable
    {
        $this->assertOpen();

        return $this->root->taggedForScope($tag, $this);
    }

    public function createScope(string $name): ScopedContainerInterface
    {
        $this->assertOpen();

        return $this->root->createScope($name);
    }

    public function diagnose(string $id): ResolutionDiagnostic
    {
        $this->assertOpen();

        return $this->root->diagnose($id);
    }

    public function scopeName(): string
    {
        return $this->name;
    }

    public function close(): void
    {
        $this->instances = [];
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * @internal
     */
    public function assertOpen(): void
    {
        if ($this->closed) {
            throw ScopeException::closed($this->name);
        }
    }

    /**
     * @internal
     */
    public function hasInstance(string $id): bool
    {
        return array_key_exists($id, $this->instances);
    }

    /**
     * @internal
     */
    public function instance(string $id): mixed
    {
        return $this->instances[$id];
    }

    /**
     * @internal
     */
    public function remember(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }
}
