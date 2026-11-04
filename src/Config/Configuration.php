<?php

declare(strict_types=1);

namespace Careminate\Config;

use InvalidArgumentException;

final class Configuration
{
    /**
     * @param array<string, mixed> $items
     */
    public function __construct(
        private readonly array $items = []
    ) {
    }

    public function has(string $key): bool
    {
        return $this->find($key)[0];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        [$found, $value] = $this->find($key);

        return $found ? $value : $default;
    }

    public function required(string $key): mixed
    {
        [$found, $value] = $this->find($key);

        if (!$found) {
            throw new InvalidArgumentException(
                sprintf('Required configuration key "%s" is missing.', $key)
            );
        }

        return $value;
    }

    public function string(string $key): string
    {
        $value = $this->required($key);

        if (!is_string($value)) {
            throw new InvalidArgumentException(
                sprintf('Configuration key "%s" must be a string.', $key)
            );
        }

        return $value;
    }

    public function boolean(string $key): bool
    {
        $value = $this->required($key);

        if (!is_bool($value)) {
            throw new InvalidArgumentException(
                sprintf('Configuration key "%s" must be a boolean.', $key)
            );
        }

        return $value;
    }

    public function integer(string $key): int
    {
        $value = $this->required($key);

        if (!is_int($value)) {
            throw new InvalidArgumentException(
                sprintf('Configuration key "%s" must be an integer.', $key)
            );
        }

        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function array(string $key): array
    {
        $value = $this->required($key);

        if (!is_array($value)) {
            throw new InvalidArgumentException(
                sprintf('Configuration key "%s" must be an array.', $key)
            );
        }

        return $value;
    }

    /**
     * @return array{bool, mixed}
     */
    private function find(string $key): array
    {
        if ($key === '') {
            return [false, null];
        }

        $current = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (
                $segment === ''
                || !is_array($current)
                || !array_key_exists($segment, $current)
            ) {
                return [false, null];
            }

            $current = $current[$segment];
        }

        return [true, $current];
    }
}
