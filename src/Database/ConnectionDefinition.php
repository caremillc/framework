<?php

declare(strict_types=1);

namespace Careminate\Database;

use InvalidArgumentException;

/**
 * Driver-neutral connection definition.
 *
 * Option names and values are interpreted by individual adapters.
 */
final readonly class ConnectionDefinition
{
    /** @var array<string, int|string> */
    private array $options;

    /**
     * @param array<string, int|string> $options
     */
    public function __construct(
        public string $driver,
        array $options,
    ) {
        if (
            preg_match(
                '/\A[a-z][a-z0-9_]*\z/D',
                $driver,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Invalid database connection configuration.',
            );
        }

        foreach (array_keys($options) as $key) {
            if (
                preg_match(
                    '/\A[a-z][a-z0-9_]*\z/D',
                    $key,
                ) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Invalid database connection configuration.',
                );
            }
        }

        $this->options = $options;
    }

    public function string(
        string $key,
        ?string $default = null,
    ): string {
        $value = $this->options[$key] ?? $default;

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                'Invalid database connection configuration.',
            );
        }

        return $value;
    }

    public function integer(
        string $key,
        ?int $default = null,
    ): int {
        $value = $this->options[$key] ?? $default;

        if (! is_int($value)) {
            throw new InvalidArgumentException(
                'Invalid database connection configuration.',
            );
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    public function optionNames(): array
    {
        return array_keys($this->options);
    }
}
