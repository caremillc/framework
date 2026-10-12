<?php

declare(strict_types=1);

namespace Flint\Http;

use InvalidArgumentException;

final class Headers
{
    /**
     * Prefix internal keys so numeric header names remain unambiguous.
     *
     * @var array<string, list<string>>
     */
    private array $fields = [];

    public function has(string $name): bool
    {
        return array_key_exists(self::key($name), $this->fields);
    }

    /**
     * @return list<string>
     */
    public function get(string $name): array
    {
        return $this->fields[self::key($name)] ?? [];
    }

    public function with(string $name, string ...$values): self
    {
        $key = self::key($name);
        $validated = self::validateValues($values);

        $updated = clone $this;
        $updated->fields[$key] = $validated;

        return $updated;
    }

    public function withAdded(string $name, string ...$values): self
    {
        $key = self::key($name);
        $validated = self::validateValues($values);

        $updated = clone $this;
        $updated->fields[$key] = [
            ...($this->fields[$key] ?? []),
            ...$validated,
        ];

        return $updated;
    }

    public function without(string $name): self
    {
        $key = self::key($name);

        $updated = clone $this;
        unset($updated->fields[$key]);

        return $updated;
    }

    /**
     * @return list<array{name: string, values: list<string>}>
     */
    public function all(): array
    {
        $headers = [];

        foreach ($this->fields as $key => $values) {
            $headers[] = [
                'name' => substr($key, 2),
                'values' => $values,
            ];
        }

        return $headers;
    }

    private static function key(string $name): string
    {
        if (
            preg_match(
                '/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/',
                $name,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Header names must be non-empty HTTP tokens.',
            );
        }

        return 'h:' . strtolower($name);
    }

    /**
     * @param array<array-key, string> $values
     *
     * @return list<string>
     */
    private static function validateValues(array $values): array
    {
        if ($values === []) {
            throw new InvalidArgumentException(
                'A header requires at least one value.',
            );
        }

        $validated = [];

        foreach ($values as $value) {
            if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) !== 0) {
                throw new InvalidArgumentException(
                    'Header values must not contain forbidden control characters.',
                );
            }

            $validated[] = trim($value, " \t");
        }

        return $validated;
    }
}
