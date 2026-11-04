<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Foundation;

use Careminate\Foundation\Framework;
use PHPUnit\Framework\TestCase;

final class FrameworkTest extends TestCase
{
    public function testFrameworkVersion(): void
    {
        self::assertSame(
            '0.1.0-dev',
            Framework::version()
        );
    }

    public function testMinimumPhpVersion(): void
    {
        self::assertSame(
            '8.4.0',
            Framework::MINIMUM_PHP_VERSION
        );
    }

    public function testRuntimeIsSupported(): void
    {
        self::assertTrue(
            Framework::supportsRuntime()
        );
    }

    public function testVersionIsNotEmpty(): void
    {
        self::assertNotSame(
            '',
            Framework::version()
        );
    }
}
