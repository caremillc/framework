<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Foundation;

use Careminate\Foundation\Version;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class VersionTest extends TestCase
{
    public function testCurrentVersionIsExposed(): void
    {
        self::assertSame('0.1.0-dev', Version::current());
        self::assertSame(0, Version::major());
        self::assertSame(1, Version::minor());
        self::assertSame(0, Version::patch());
    }

    public function testDevelopmentVersionIsNotStable(): void
    {
        self::assertTrue(Version::isDevelopment());
        self::assertFalse(Version::isStable());
    }

    public function testVersionClassCannotBeInstantiated(): void
    {
        $reflection = new ReflectionClass(Version::class);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }
}
