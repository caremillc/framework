<?php

declare(strict_types=1);

namespace Careminate\Foundation;

final class Framework
{
    public const VERSION = '0.1.0-dev';

    public const MINIMUM_PHP_VERSION = '8.4.0';

    public static function version(): string
    {
        return self::VERSION;
    }

    public static function supportsRuntime(): bool
    {
        return version_compare(
            PHP_VERSION,
            self::MINIMUM_PHP_VERSION,
            '>='
        );
    }
}
