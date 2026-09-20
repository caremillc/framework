<?php

declare(strict_types=1);

namespace Careminate\Module;

/**
 * Restricted service registration available to providers.
 *
 * @api
 */
interface ServiceRegistryInterface
{
    public function value(string $id, mixed $value): void;

    /**
     * @param array<string, mixed> $arguments
     */
    public function autowire(
        string $id,
        string $className,
        ServiceLifetime $lifetime = ServiceLifetime::Transient,
        array $arguments = [],
        bool $lazy = false,
    ): void;
}
