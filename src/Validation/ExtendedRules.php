<?php

declare(strict_types=1);

namespace Careminate\Validation;

use InvalidArgumentException;

/**
 * Optional scalar and list constraints shared by flat and nested schemas.
 *
 * Schemas are developer-controlled and checked before input validation.
 *
 * @internal
 */
final class ExtendedRules
{
    private const int MAX_ENUM_CHOICES = 64;
    private const int MAX_ENUM_STRING_BYTES = 256;

    /**
     * @param array<string, mixed> $rules
     */
    public static function assertSchema(
        array $rules,
        string $type,
    ): void {
        if (array_key_exists('maxBytes', $rules)) {
            if (
                $type !== 'string'
                || ! is_int($rules['maxBytes'])
                || $rules['maxBytes'] < 0
            ) {
                throw new InvalidArgumentException(
                    'maxBytes requires a nonnegative integer string bound.',
                );
            }
        }

        if (array_key_exists('enum', $rules)) {
            if (
                ! in_array(
                    $type,
                    ['string', 'integer', 'boolean'],
                    true,
                )
            ) {
                throw new InvalidArgumentException(
                    'enum supports scalar fields only.',
                );
            }

            $choices = $rules['enum'];

            if (
                ! is_array($choices)
                || ! array_is_list($choices)
                || $choices === []
                || count($choices) > self::MAX_ENUM_CHOICES
            ) {
                throw new InvalidArgumentException(
                    'enum requires 1 to 64 ordered choices.',
                );
            }

            $seen = [];

            foreach ($choices as $choice) {
                if (
                    (
                        $type === 'string'
                        && (
                            ! is_string($choice)
                            || strlen($choice) > self::MAX_ENUM_STRING_BYTES
                            || preg_match('//u', $choice) !== 1
                        )
                    )
                    || (
                        $type === 'integer'
                        && ! is_int($choice)
                    )
                    || (
                        $type === 'boolean'
                        && ! is_bool($choice)
                    )
                ) {
                    throw new InvalidArgumentException(
                        'enum choices must match their declared scalar type.',
                    );
                }

                if (in_array($choice, $seen, true)) {
                    throw new InvalidArgumentException(
                        'enum choices must be unique.',
                    );
                }

                $seen[] = $choice;
            }
        }

        if (array_key_exists('uniqueItems', $rules)) {
            if (
                $type !== 'array'
                || ! is_bool($rules['uniqueItems'])
            ) {
                throw new InvalidArgumentException(
                    'uniqueItems must be a boolean list constraint.',
                );
            }

            if ($rules['uniqueItems'] === true) {
                $items = $rules['items'] ?? null;

                if (
                    ! is_array($items)
                    || ! in_array(
                        $items['type'] ?? null,
                        ['string', 'integer', 'boolean'],
                        true,
                    )
                ) {
                    throw new InvalidArgumentException(
                        'uniqueItems supports primitive list elements only.',
                    );
                }
            }
        }
    }

    /**
     * Returns stable error codes without exposing the submitted value.
     *
     * @param array<string, mixed> $rules
     * @return list<string>
     */
    public static function scalarErrors(
        string|int|bool $value,
        array $rules,
    ): array {
        $errors = [];

        if (
            is_string($value)
            && isset($rules['maxBytes'])
            && strlen($value) > $rules['maxBytes']
        ) {
            $errors[] = 'max_bytes';
        }

        if (array_key_exists('enum', $rules)) {
            /** @var list<string|int|bool> $choices */
            $choices = $rules['enum'];

            if (! in_array($value, $choices, true)) {
                $errors[] = 'enum';
            }
        }

        return $errors;
    }

    /**
     * Strict, linear-time duplicate detection without type coercion.
     *
     * Unsupported item types are handled by the existing item validator.
     *
     * @param list<mixed> $items
     */
    public static function hasDuplicateScalarItems(
        array $items,
    ): bool {
        $seen = [];

        foreach ($items as $item) {
            $key = self::itemKey($item);

            if ($key === null) {
                continue;
            }

            if (isset($seen[$key])) {
                return true;
            }

            $seen[$key] = true;
        }

        return false;
    }

    private static function itemKey(mixed $item): ?string
    {
        if (is_string($item)) {
            return 's:' . $item;
        }

        if (is_int($item)) {
            return 'i:' . $item;
        }

        if (is_bool($item)) {
            return $item ? 'b:1' : 'b:0';
        }

        return $item === null ? 'n:' : null;
    }
}
