<?php

declare(strict_types=1);

namespace Careminate\Application\Optimization;

use Careminate\Application\ApplicationPaths;
use Careminate\Container\Compiler\ContainerCompiler;
use Careminate\Container\Container;
use Careminate\Exception\Application\OptimizationException;
use RuntimeException;
use Throwable;

final readonly class ApplicationOptimizer
{
    public function __construct(
        private ContainerCompiler $compiler = new ContainerCompiler(),
    ) {
    }

    public function optimize(
        Container $container,
        ApplicationPaths $paths,
    ): OptimizationReport {
        $cachePath = $paths->containerCache();
        $startedAt = microtime(true);
        $memoryBefore = memory_get_usage(true);

        try {
            $this->compiler->compile($container, $cachePath);
        } catch (Throwable $exception) {
            throw OptimizationException::failed($cachePath, $exception);
        }

        $size = filesize($cachePath);

        if ($size === false) {
            throw OptimizationException::failed(
                $cachePath,
                new RuntimeException('Unable to measure cache size.'),
            );
        }

        return new OptimizationReport(
            $cachePath,
            (microtime(true) - $startedAt) * 1000,
            memory_get_usage(true) - $memoryBefore,
            $size,
        );
    }

    public function load(ApplicationPaths $paths): Container
    {
        $cachePath = $paths->containerCache();

        try {
            return $this->compiler->load($cachePath);
        } catch (Throwable $exception) {
            throw OptimizationException::failed($cachePath, $exception);
        }
    }

    public function clear(ApplicationPaths $paths): void
    {
        $cachePath = $paths->containerCache();

        if (!is_file($cachePath)) {
            return;
        }

        if (!unlink($cachePath)) {
            throw OptimizationException::clearFailed($cachePath);
        }
    }
}
