<?php

declare(strict_types=1);

namespace Flint\Http;

use InvalidArgumentException;

final class QueryStringDecoder
{
    /**
     * @return array<array-key, string>
     */
    public static function decode(
        ?string $query,
        int $maxParameters = 100,
        int $maxBytes = 8192,
    ): array {
        if ($maxParameters < 0 || $maxBytes < 0) {
            throw new InvalidArgumentException(
                'Query limits must be non-negative.',
            );
        }

        if ($query === null || $query === '') {
            return [];
        }

        if (strlen($query) > $maxBytes) {
            throw new InvalidArgumentException(
                'The query string exceeds the configured byte limit.',
            );
        }

        $fields = explode('&', $query);

        if (count($fields) > $maxParameters) {
            throw new InvalidArgumentException(
                'The query string exceeds the configured parameter limit.',
            );
        }

        $parameters = [];

        foreach ($fields as $field) {
            if (preg_match('/%(?![0-9A-Fa-f]{2})/', $field) === 1) {
                throw new InvalidArgumentException(
                    'The query string contains an invalid percent escape.',
                );
            }

            $parts = explode('=', $field, 2);
            $name = urldecode($parts[0]);
            $value = urldecode($parts[1] ?? '');

            if ($name === '') {
                throw new InvalidArgumentException(
                    'Query parameter names must not be empty.',
                );
            }

            if (array_key_exists($name, $parameters)) {
                throw new InvalidArgumentException(
                    'The query string contains duplicate parameter names.',
                );
            }

            $parameters[$name] = $value;
        }

        return $parameters;
    }
}
