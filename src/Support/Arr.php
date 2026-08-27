<?php

declare(strict_types=1);

namespace Careminate\Support;

use Careminate\Exception\InvalidArgumentException;

final class Arr
{
    private function __construct()
    {
    }

    /**
     * Retrieve a value using a direct key or dot-separated path.
     *
     * A direct key takes precedence when the array contains both a literal
     * dotted key and a matching nested path.
     *
     * @param array<array-key, mixed> $array
     */
    public static function get(
        array $array,
        string|int|null $key,
        mixed $default = null,
    ): mixed {
        if ($key === null) {
            return $array;
        }

        if (array_key_exists($key, $array)) {
            return $array[$key];
        }

        if (is_int($key)) {
            return $default;
        }

        $value = $array;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Determine whether a direct key or dot-separated path exists.
     *
     * @param array<array-key, mixed> $array
     */
    public static function has(array $array, string|int $key): bool
    {
        if (array_key_exists($key, $array)) {
            return true;
        }

        if (is_int($key)) {
            return false;
        }

        $value = $array;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }

            $value = $value[$segment];
        }

        return true;
    }

    /**
     * Return a new array containing the supplied value.
     *
     * Dot-separated string keys create nested arrays unless an exact dotted
     * key already exists in the source array.
     *
     * @param array<array-key, mixed> $array
     *
     * @return array<array-key, mixed>
     */
    public static function set(
        array $array,
        string|int $key,
        mixed $value,
    ): array {
        if (is_int($key) || array_key_exists($key, $array)) {
            $array[$key] = $value;

            return $array;
        }

        if ($key === '') {
            throw new InvalidArgumentException('An array path must not be empty.');
        }

        $segments = explode('.', $key);

        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new InvalidArgumentException(
                    sprintf('Array path "%s" contains an empty segment.', $key),
                );
            }
        }

        return self::setSegments($array, $segments, $value);
    }

    /**
     * Return only the requested direct keys.
     *
     * @param array<array-key, mixed> $array
     * @param list<array-key>         $keys
     *
     * @return array<array-key, mixed>
     */
    public static function only(array $array, array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                $result[$key] = $array[$key];
            }
        }

        return $result;
    }

    /**
     * Return the array without the requested direct keys.
     *
     * @param array<array-key, mixed> $array
     * @param list<array-key>         $keys
     *
     * @return array<array-key, mixed>
     */
    public static function except(array $array, array $keys): array
    {
        foreach ($keys as $key) {
            unset($array[$key]);
        }

        return $array;
    }

    /**
     * @param array<array-key, mixed> $array
     * @param list<string>            $segments
     *
     * @return array<array-key, mixed>
     */
    private static function setSegments(
        array $array,
        array $segments,
        mixed $value,
    ): array {
        if ($segments === []) {
            return $array;
        }

        $segment = $segments[0];
        $remaining = array_slice($segments, 1);

        if ($remaining === []) {
            $array[$segment] = $value;

            return $array;
        }

        $child = $array[$segment] ?? [];

        if (!is_array($child)) {
            $child = [];
        }

        $array[$segment] = self::setSegments($child, $remaining, $value);

        return $array;
    }
}
