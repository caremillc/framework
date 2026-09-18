<?php

declare(strict_types=1);

namespace Careminate\Container;

use Closure;
use LogicException;

/**
 * Explicit deferred access to a container service.
 *
 * @api
 */
final class DeferredService
{
    /**
     * Container-managed construction.
     *
     * @param Closure(): mixed $resolver
     *
     * @internal
     */
    public function __construct(private readonly Closure $resolver)
    {
    }

    public function get(): mixed
    {
        return ($this->resolver)();
    }

    /**
     * @return array<array-key, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException(
            'Deferred service references cannot be serialized.',
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException(
            'Deferred service references cannot be unserialized.',
        );
    }
}
