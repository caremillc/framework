<?php

declare(strict_types=1);

namespace Flint\Configuration;

use Flint\Configuration\Exception\ConfigurationException;

final readonly class Environment
{
    /**
     * @var array<string, string>
     */
    private array $values;

    /**
     * @param array<array-key, mixed> $values
     */
    public function __construct(array $values = [])
    {
        $validated = [];

        foreach ($values as $name => $value) {
            if (!is_string($name) || $name === '') {
                throw new ConfigurationException(
                    'Environment names must be non-empty strings.',
                );
            }

            if (!is_string($value)) {
                throw new ConfigurationException(
                    sprintf(
                        'Environment variable "%s" must contain a string.',
                        $name,
                    ),
                );
            }

            $validated[$name] = $value;
        }

        $this->values = $validated;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function string(string $name): string
    {
        if (!$this->has($name)) {
            throw new ConfigurationException(
                sprintf(
                    'Environment variable "%s" is missing.',
                    $name,
                ),
            );
        }

        return $this->values[$name];
    }

    public function boolean(string $name): bool
    {
        return match ($this->string($name)) {
            'true', '1' => true,
            'false', '0' => false,
            default => throw self::invalidValue($name, 'boolean'),
        };
    }

    public function integer(string $name): int
    {
        $value = $this->string($name);

        if (preg_match('/\A(?:0|-?[1-9][0-9]*)\z/', $value) !== 1) {
            throw self::invalidValue($name, 'integer');
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        if (!is_int($parsed)) {
            throw self::invalidValue($name, 'integer');
        }

        return $parsed;
    }

    private static function invalidValue(
        string $name,
        string $expected,
    ): ConfigurationException {
        return new ConfigurationException(
            sprintf(
                'Environment variable "%s" is not a valid %s.',
                $name,
                $expected,
            ),
        );
    }
}
