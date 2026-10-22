<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use Careminate\Container\Container;
use Careminate\Container\Exception\ContainerException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

interface LoggerContract
{
}

final class TestLogger implements LoggerContract
{
}

final class SimpleService
{
}

final class ServiceWithDependency
{
    public function __construct(
        public SimpleService $dependency
    ) {
    }
}

final class ServiceWithLogger
{
    public function __construct(
        public LoggerContract $logger
    ) {
    }
}

final class ServiceWithScalar
{
    public function __construct(
        public string $name
    ) {
    }
}

final class ServiceWithDefault
{
    public function __construct(
        public string $name = 'careminate'
    ) {
    }
}

final class ServiceWithTwoDependencies
{
    public function __construct(
        public SimpleService $first,
        public TestLogger $second
    ) {
    }
}

final class CircularFirst
{
    public function __construct(
        public CircularSecond $second
    ) {
    }
}

final class CircularSecond
{
    public function __construct(
        public CircularFirst $first
    ) {
    }
}

abstract class AbstractService
{
}

final class AutowiringTest extends TestCase
{
    public function testConcreteClassCanBeAutowired(): void
    {
        $container = new Container();

        $container->autowire(SimpleService::class);

        self::assertInstanceOf(
            SimpleService::class,
            $container->get(SimpleService::class)
        );
    }

    public function testAutowiredBindingIsTransientByDefault(): void
    {
        $container = new Container();

        $container->autowire(SimpleService::class);

        self::assertNotSame(
            $container->get(SimpleService::class),
            $container->get(SimpleService::class)
        );
    }

    public function testAutowiredSingletonPreservesIdentity(): void
    {
        $container = new Container();

        $container->autowire(SimpleService::class, true);

        self::assertSame(
            $container->get(SimpleService::class),
            $container->get(SimpleService::class)
        );
    }

    public function testConstructorDependencyIsResolved(): void
    {
        $container = new Container();

        $container->autowire(ServiceWithDependency::class);

        $service = $container->get(ServiceWithDependency::class);

        self::assertInstanceOf(
            ServiceWithDependency::class,
            $service
        );

        self::assertInstanceOf(
            SimpleService::class,
            $service->dependency
        );
    }

    public function testExplicitInterfaceBindingIsInjected(): void
    {
        $container = new Container();
        $logger = new TestLogger();

        $container->instance(LoggerContract::class, $logger);
        $container->autowire(ServiceWithLogger::class);

        $service = $container->get(ServiceWithLogger::class);

        self::assertInstanceOf(
            ServiceWithLogger::class,
            $service
        );

        self::assertSame($logger, $service->logger);
    }

    public function testDefaultScalarParameterIsSupported(): void
    {
        $container = new Container();

        $container->autowire(ServiceWithDefault::class);

        $service = $container->get(ServiceWithDefault::class);

        self::assertInstanceOf(
            ServiceWithDefault::class,
            $service
        );

        self::assertSame('careminate', $service->name);
    }

    public function testRequiredScalarParameterFails(): void
    {
        $container = new Container();

        $container->autowire(ServiceWithScalar::class);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('parameter "$name"');

        $container->get(ServiceWithScalar::class);
    }

    public function testUnboundInterfaceFails(): void
    {
        $container = new Container();

        $container->autowire(ServiceWithLogger::class);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('parameter "$logger"');

        $container->get(ServiceWithLogger::class);
    }

    public function testCircularConstructorDependenciesFail(): void
    {
        $container = new Container();

        $container->autowire(CircularFirst::class);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage(
            'Circular dependency detected'
        );

        $container->get(CircularFirst::class);
    }

    public function testAbstractClassCannotBeRegistered(): void
    {
        $container = new Container();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not instantiable');

        $container->autowire(AbstractService::class);
    }

    public function testRegisteredSingletonDependencyIsReused(): void
    {
        $container = new Container();

        $container->autowire(SimpleService::class, true);
        $container->autowire(ServiceWithDependency::class);

        $service = $container->get(ServiceWithDependency::class);

        self::assertInstanceOf(
            ServiceWithDependency::class,
            $service
        );

        self::assertSame(
            $container->get(SimpleService::class),
            $service->dependency
        );
    }

    public function testMultipleConstructorDependenciesResolve(): void
    {
        $container = new Container();

        $container->autowire(ServiceWithTwoDependencies::class);

        $service = $container->get(ServiceWithTwoDependencies::class);

        self::assertInstanceOf(
            ServiceWithTwoDependencies::class,
            $service
        );

        self::assertInstanceOf(
            SimpleService::class,
            $service->first
        );

        self::assertInstanceOf(
            TestLogger::class,
            $service->second
        );
    }
}
