<?php

declare(strict_types=1);

namespace Careminate\Database;

use InvalidArgumentException;

/** Normalize SQL values and placeholder names without depending on PDO. */
final class SqlBindings
{
    /**
     * @param array<int|string, mixed> $parameters
     * @return list<array{
     *     placeholder: int|string,
     *     value: int|string|bool|null
     * }>
     */
    public static function normalize(
        array $parameters,
    ): array {
        $positional = array_is_list($parameters);
        $bindings = [];
        $index = 0;

        foreach ($parameters as $key => $value) {
            if (
                ! is_int($value)
                && ! is_string($value)
                && ! is_bool($value)
                && $value !== null
            ) {
                throw new InvalidArgumentException(
                    'Invalid SQL parameter value.',
                );
            }

            if ($positional) {
                // PDO positional binding is 1-based.
                $placeholder = ++$index;
            } else {
                if (
                    ! is_string($key)
                    || preg_match(
                        '/\A[A-Za-z_][A-Za-z0-9_]*\z/D',
                        $key,
                    ) !== 1
                ) {
                    throw new InvalidArgumentException(
                        'Invalid SQL parameter name.',
                    );
                }

                $placeholder = ':' . $key;
            }

            $bindings[] = [
                'placeholder' => $placeholder,
                'value' => $value,
            ];
        }

        return $bindings;
    }
}
