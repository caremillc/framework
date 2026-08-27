<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Application;

use Careminate\Application\ApplicationPaths;
use Careminate\Application\Optimization\ApplicationOptimizer;
use Careminate\Container\Container;
use Careminate\Exception\Container\FrozenContainerException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OptimizedApplicationService
{
}

final class OptimizationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'careminate-application-'
            . bin2hex(random_bytes(8));

        if (!mkdir($this->directory, 0770, true)) {
            throw new RuntimeException(
                'Unable to create optimization test directory.',
            );
        }

        if (!mkdir($this->directory . DIRECTORY_SEPARATOR . 'cache')) {
            throw new RuntimeException(
                'Unable to create optimization cache directory.',
            );
        }
    }

    protected function tearDown(): void
    {
        $cache = $this->directory
            . DIRECTORY_SEPARATOR
            . 'cache'
            . DIRECTORY_SEPARATOR
            . 'container.php';

        if (is_file($cache)) {
            unlink($cache);
        }

        $cacheDirectory = $this->directory
            . DIRECTORY_SEPARATOR
            . 'cache';

        if (is_dir($cacheDirectory)) {
            rmdir($cacheDirectory);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testApplicationContainerCanBeOptimizedAndLoaded(): void
    {
        $paths = ApplicationPaths::fromBasePath(
            $this->directory,
            [
                'cache' => 'cache',
            ],
        );

        $container = new Container();
        $container->singleton(OptimizedApplicationService::class);

        $optimizer = new ApplicationOptimizer();
        $report = $optimizer->optimize($container, $paths);

        self::assertSame($paths->containerCache(), $report->cachePath);
        self::assertGreaterThan(0, $report->cacheSizeBytes);
        self::assertGreaterThanOrEqual(0.0, $report->durationMilliseconds);

        $compiled = $optimizer->load($paths);

        self::assertTrue($compiled->isFrozen());
        self::assertInstanceOf(
            OptimizedApplicationService::class,
            $compiled->get(OptimizedApplicationService::class),
        );

        $this->expectException(FrozenContainerException::class);

        $compiled->bind('new.service', OptimizedApplicationService::class);
    }

    public function testOptimizationCacheCanBeCleared(): void
    {
        $paths = ApplicationPaths::fromBasePath(
            $this->directory,
            [
                'cache' => 'cache',
            ],
        );

        $container = new Container();
        $container->singleton(OptimizedApplicationService::class);

        $optimizer = new ApplicationOptimizer();
        $optimizer->optimize($container, $paths);
        $optimizer->clear($paths);

        self::assertFileDoesNotExist($paths->containerCache());
    }
}
