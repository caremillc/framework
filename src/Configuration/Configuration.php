<?php

declare(strict_types=1);

namespace Flint\Configuration;

use Flint\Configuration\Exception\ConfigurationException;

final readonly class Configuration
{
    /**
     * @var array<string, scalar|null>
     */
    private array $values;

    /**
     * @param array<array-key, mixed> $values
     */
    public function __construct(array $values = [])
    {
        $validated = [];

        foreach ($values as $key => $value) {
            if (! is_string($key) || $key === '') {
                throw new ConfigurationException(
                    'Configuration keys must be non-empty strings.',
                );
            }

            if (! is_scalar($value) && $value !== null) {
                throw new ConfigurationException(
                    sprintf(
                        'Configuration key "%s" must contain a scalar value or null.',
                        $key,
                    ),
                );
            }

            $validated[$key] = $value;
        }

        $this->values = $validated;
    }

    /**
     * Later layers override earlier layers.
     *
     * @param array<array-key, mixed> ...$layers
     */
    public static function fromLayers(array ...$layers): self
    {
        $values = [];

        foreach ($layers as $layer) {
            $configuration = new self($layer);

            $values = array_replace($values, $configuration->values);
        }

        return new self($values);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key): string | int | float | bool | null
    {
        if (! $this->has($key)) {
            throw new ConfigurationException(
                sprintf(
                    'Configuration key "%s" is missing.',
                    $key,
                ),
            );
        }

        return $this->values[$key];
    }

    public function string(string $key): string
    {
        $value = $this->get($key);

        if (! is_string($value)) {
            throw self::typeMismatch($key, 'string');
        }

        return $value;
    }

    public function integer(string $key): int
    {
        $value = $this->get($key);

        if (! is_int($value)) {
            throw self::typeMismatch($key, 'integer');
        }

        return $value;
    }

    public function boolean(string $key): bool
    {
        $value = $this->get($key);

        if (! is_bool($value)) {
            throw self::typeMismatch($key, 'boolean');
        }

        return $value;
    }

    public function float(string $key): float
    {
        $value = $this->get($key);

        if (! is_float($value)) {
            throw self::typeMismatch($key, 'float');
        }

        return $value;
    }

    private static function typeMismatch(
        string $key,
        string $expected,
    ): ConfigurationException {
        return new ConfigurationException(
            sprintf(
                'Configuration key "%s" must be a %s.',
                $key,
                $expected,
            ),
        );
    }
}
