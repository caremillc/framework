<?php

declare(strict_types=1);

namespace Flint\Http;

use InvalidArgumentException;

final class QueryStringEncoder
{
    /**
     * @param array<array-key, mixed> $parameters
     */
    public static function encode(array $parameters): string
    {
        $pairs = [];

        foreach ($parameters as $name => $value) {
            if (! is_string($name) || $name === '') {
                throw new InvalidArgumentException(
                    'Query parameter names must be non-empty strings.',
                );
            }

            if ($value === null) {
                continue;
            }

            if (
                ! is_string($value)
                && ! is_int($value)
                && ! is_bool($value)
            ) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Query parameter "%s" must be a string, integer, boolean, or null.',
                        $name,
                    ),
                );
            }

            $text = is_bool($value)
                ? ($value ? '1' : '0')
                : (string) $value;

            $pairs[] = rawurlencode($name) . '=' . rawurlencode($text);
        }

        return implode('&', $pairs);
    }
}
