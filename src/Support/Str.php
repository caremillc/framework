<?php

declare(strict_types=1);

namespace Careminate\Support;

use Careminate\Exception\UnexpectedValueException;

final class Str
{
    private function __construct()
    {
    }

    public static function isBlank(string $value): bool
    {
        return trim($value) === '';
    }

    public static function contains(string $value, string $needle): bool
    {
        return str_contains($value, $needle);
    }

    public static function startsWith(string $value, string $prefix): bool
    {
        return str_starts_with($value, $prefix);
    }

    public static function endsWith(string $value, string $suffix): bool
    {
        return str_ends_with($value, $suffix);
    }

    public static function snake(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = self::replacePattern(
            '/([A-Z]+)([A-Z][a-z])/',
            '$1_$2',
            $value,
        );

        $value = self::replacePattern(
            '/([a-z0-9])([A-Z])/',
            '$1_$2',
            $value,
        );

        $value = self::replacePattern('/[\s\-]+/', '_', $value);
        $value = self::replacePattern('/_+/', '_', $value);

        return strtolower(trim($value, '_'));
    }

    public static function kebab(string $value): string
    {
        return str_replace('_', '-', self::snake($value));
    }

    public static function studly(string $value): string
    {
        $snake = self::snake($value);

        if ($snake === '') {
            return '';
        }

        $result = '';

        foreach (explode('_', $snake) as $segment) {
            $result .= ucfirst($segment);
        }

        return $result;
    }

    public static function camel(string $value): string
    {
        return lcfirst(self::studly($value));
    }

    private static function replacePattern(
        string $pattern,
        string $replacement,
        string $subject,
    ): string {
        $result = preg_replace($pattern, $replacement, $subject);

        if ($result === null) {
            throw new UnexpectedValueException(
                sprintf('String transformation failed for pattern "%s".', $pattern),
            );
        }

        return $result;
    }
}
