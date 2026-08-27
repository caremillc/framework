<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use Careminate\Container\Container;
use Careminate\Exception\Container\ScopeException;
use PHPUnit\Framework\TestCase;

final class TransientService
{
}

final class SingletonService
{
}

final class RequestService
{
}

final class LifetimeTest extends TestCase
{
    public function testTransientBindingCreatesNewInstances(): void
    {
        $container = new Container();
        $container->bind(TransientService::class);

        self::assertNotSame(
            $container->get(TransientService::class),
            $container->get(TransientService::class),
        );
    }

    public function testSingletonBindingReusesRootInstance(): void
    {
        $container = new Container();
        $container->singleton(SingletonService::class);

        self::assertSame(
            $container->get(SingletonService::class),
            $container->get(SingletonService::class),
        );
    }

    public function testScopedBindingIsStableOnlyWithinItsScope(): void
    {
        $container = new Container();
        $container->scoped(RequestService::class);

        $firstScope = $container->createScope('request-1');
        $secondScope = $container->createScope('request-2');

        $firstInstance = $firstScope->get(RequestService::class);

        self::assertSame(
            $firstInstance,
            $firstScope->get(RequestService::class),
        );

        self::assertNotSame(
            $firstInstance,
            $secondScope->get(RequestService::class),
        );
    }

    public function testScopedBindingCannotResolveFromRoot(): void
    {
        $container = new Container();
        $container->scoped(RequestService::class);

        $this->expectException(ScopeException::class);
        $this->expectExceptionMessage('must be resolved through a scoped container');

        $container->get(RequestService::class);
    }

    public function testClosedScopeRejectsFurtherResolution(): void
    {
        $container = new Container();
        $container->scoped(RequestService::class);

        $scope = $container->createScope('request');
        $scope->get(RequestService::class);
        $scope->close();

        $this->expectException(ScopeException::class);
        $this->expectExceptionMessage('already closed');

        $scope->get(RequestService::class);
    }
}
