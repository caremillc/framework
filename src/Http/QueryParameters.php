<?php

declare(strict_types=1);

namespace Flint\Http;

use InvalidArgumentException;

final readonly class QueryParameters
{
    /** @var array<string, string> */
    private array $values;

    /**
     * @param array<array-key, mixed> $values
     */
    public function __construct(array $values)
    {
        $validated = [];

        foreach ($values as $name => $value) {
            if (
                ! is_string($name)
                || $name === ''
                || ! is_string($value)
            ) {
                throw new InvalidArgumentException(
                    'Query parameters require non-empty string names and string values.',
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

    public function string(
        string $name,
        ?string $default = null,
    ): ?string {
        return $this->values[$name] ?? $default;
    }

    public function integer(
        string $name,
        ?int $default = null,
    ): ?int {
        if (! $this->has($name)) {
            return $default;
        }

        $value = $this->values[$name];

        if (
            preg_match('/\A(?:0|-?[1-9][0-9]*)\z/', $value) !== 1
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Query parameter "%s" must contain a canonical decimal integer.',
                    $name,
                ),
            );
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        if ($integer === false) {
            throw new InvalidArgumentException(
                sprintf(
                    'Query parameter "%s" is outside the supported integer range.',
                    $name,
                ),
            );
        }

        return $integer;
    }

    public function boolean(
        string $name,
        ?bool $default = null,
    ): ?bool {
        if (! $this->has($name)) {
            return $default;
        }

        return match ($this->values[$name]) {
            'true', '1' => true,
            'false', '0' => false,
            default => throw new InvalidArgumentException(
                sprintf(
                    'Query parameter "%s" must contain true, false, 1, or 0.',
                    $name,
                ),
            ),
        };
    }
}
