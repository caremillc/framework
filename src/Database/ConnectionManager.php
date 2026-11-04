<?php

declare(strict_types=1);

namespace Careminate\Database;

use Closure;
use InvalidArgumentException;

/**
 * Resolve and cache database connections by name.
 *
 * Adapter factories are injected rather than hardcoded.
 */
final class ConnectionManager
{
    /** @var array<string, Connection> */
    private array $connections = [];

    /**
     * @param array<string, ConnectionDefinition> $definitions
     * @param array<string, Closure(ConnectionDefinition): Connection> $factories
     */
    public function __construct(
        private readonly string $default,
        private readonly array $definitions,
        private readonly array $factories,
    ) {
        if (
            $default === ''
            || ! isset($definitions[$default])
        ) {
            throw new InvalidArgumentException(
                'Default database connection is not configured.',
            );
        }

        foreach (array_keys($definitions) as $name) {
            if (
                preg_match(
                    '/\A[a-z][a-z0-9_]*\z/D',
                    $name,
                ) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Invalid database connection definition.',
                );
            }
        }
    }

    public function connection(
        ?string $name = null,
    ): Connection {
        $selected = $name ?? $this->default;

        $definition = $this->definitions[$selected] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException(
                'Unknown database connection.',
            );
        }

        if (isset($this->connections[$selected])) {
            return $this->connections[$selected];
        }

        $factory = $this->factories[$definition->driver] ?? null;

        if ($factory === null) {
            throw new InvalidArgumentException(
                'Unsupported database driver.',
            );
        }

        $connection = $factory($definition);

        $this->connections[$selected] = $connection;

        return $connection;
    }

    public function disconnectAll(): void
    {
        foreach ($this->connections as $connection) {
            $connection->disconnect();
        }

        $this->connections = [];
    }
}
