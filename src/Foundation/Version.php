<?php

declare(strict_types=1);

namespace Careminate\Foundation;

final class Version
{
    public const CURRENT = '0.1.0-dev';

    public const MAJOR = 0;

    public const MINOR = 1;

    public const PATCH = 0;

    private function __construct()
    {
    }

    public static function current(): string
    {
        return self::CURRENT;
    }

    public static function major(): int
    {
        return self::MAJOR;
    }

    public static function minor(): int
    {
        return self::MINOR;
    }

    public static function patch(): int
    {
        return self::PATCH;
    }

    public static function isDevelopment(): bool
    {
        return str_ends_with(self::CURRENT, '-dev');
    }

    public static function isStable(): bool
    {
        return !str_contains(self::CURRENT, '-');
    }
}
