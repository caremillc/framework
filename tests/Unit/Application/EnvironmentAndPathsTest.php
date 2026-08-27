<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Application;

use Careminate\Application\ApplicationEnvironment;
use Careminate\Application\ApplicationPath;
use Careminate\Application\ApplicationPaths;
use Careminate\Exception\Application\InvalidApplicationPathException;
use Careminate\Exception\Application\InvalidEnvironmentException;
use PHPUnit\Framework\TestCase;

final class EnvironmentAndPathsTest extends TestCase
{
    public function testProductionEnvironmentUsesSecureDefaults(): void
    {
        $environment = ApplicationEnvironment::production();

        self::assertSame('production', $environment->name());
        self::assertFalse($environment->debug());
        self::assertTrue($environment->productionMode());
    }

    public function testProductionEnvironmentRejectsDebugMode(): void
    {
        $this->expectException(InvalidEnvironmentException::class);

        ApplicationEnvironment::custom(
            'production-blue',
            true,
            true,
        );
    }

    public function testEnvironmentNameIsValidated(): void
    {
        $this->expectException(InvalidEnvironmentException::class);

        ApplicationEnvironment::custom('Invalid Environment');
    }

    public function testWindowsApplicationPathsAreNormalized(): void
    {
        $paths = ApplicationPaths::fromBasePath(
            'C:\\xampp\\htdocs\\caremi',
        );

        self::assertSame(
            'C:/xampp/htdocs/caremi',
            $paths->base(),
        );

        self::assertSame(
            'C:/xampp/htdocs/caremi/bootstrap/cache',
            $paths->cache(),
        );
    }

    public function testRelativeOverrideIsResolvedFromBase(): void
    {
        $paths = ApplicationPaths::fromBasePath(
            'C:\\xampp\\htdocs\\caremi',
            [
                'storage' => 'var\\storage',
            ],
        );

        self::assertSame(
            'C:/xampp/htdocs/caremi/var/storage',
            $paths->storage(),
        );
    }

    public function testImmutablePathOverrideCreatesNewInstance(): void
    {
        $original = ApplicationPaths::fromBasePath('/var/www/caremi');

        $modified = $original->with(
            ApplicationPath::Storage,
            '/mnt/caremi-storage',
        );

        self::assertSame(
            '/var/www/caremi/storage',
            $original->storage(),
        );

        self::assertSame(
            '/mnt/caremi-storage',
            $modified->storage(),
        );
    }

    public function testRelativeBasePathIsRejected(): void
    {
        $this->expectException(InvalidApplicationPathException::class);

        ApplicationPaths::fromBasePath('caremi');
    }
}
