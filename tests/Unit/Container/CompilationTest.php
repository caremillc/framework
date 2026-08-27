<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use Careminate\Container\Compiler\ContainerCompiler;
use Careminate\Container\Container;
use Careminate\Exception\Container\ContainerCompilationException;
use Careminate\Exception\Container\FrozenContainerException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CompiledService
{
}

final class CompilationTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'careminate-container-');

        if ($path === false) {
            throw new RuntimeException('Unable to allocate test cache path.');
        }

        $this->cachePath = $path;
    }

    protected function tearDown(): void
    {
        if (is_file($this->cachePath)) {
            unlink($this->cachePath);
        }
    }

    public function testContainerCanBeCompiledAndLoaded(): void
    {
        $container = new Container();

        $container->singleton(CompiledService::class);
        $container->alias('compiled.service', CompiledService::class);
        $container->instance('application.name', 'Caremi');

        $compiler = new ContainerCompiler();
        $compiler->compile($container, $this->cachePath);

        $compiled = $compiler->load($this->cachePath);

        self::assertTrue($compiled->isFrozen());
        self::assertInstanceOf(
            CompiledService::class,
            $compiled->get('compiled.service'),
        );

        self::assertSame(
            $compiled->get(CompiledService::class),
            $compiled->get(CompiledService::class),
        );

        self::assertSame('Caremi', $compiled->get('application.name'));
    }

    public function testCompiledContainerRejectsMutation(): void
    {
        $container = new Container();
        $container->singleton(CompiledService::class);

        $compiler = new ContainerCompiler();
        $compiler->compile($container, $this->cachePath);

        $compiled = $compiler->load($this->cachePath);

        $this->expectException(FrozenContainerException::class);

        $compiled->bind('another.service', CompiledService::class);
    }

    public function testClosureFactoryCannotBeCompiled(): void
    {
        $container = new Container();

        $container->factory(
            'runtime.factory',
            static fn (): object => new CompiledService(),
        );

        $this->expectException(ContainerCompilationException::class);
        $this->expectExceptionMessage('non-compilable');

        (new ContainerCompiler())->compile(
            $container,
            $this->cachePath,
        );
    }

    public function testObjectInstanceCannotBeCompiled(): void
    {
        $container = new Container();
        $container->instance('runtime.object', new CompiledService());

        $this->expectException(ContainerCompilationException::class);
        $this->expectExceptionMessage('cannot export');

        (new ContainerCompiler())->compile(
            $container,
            $this->cachePath,
        );
    }
}
