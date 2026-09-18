<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use Careminate\Container\Container;
use Careminate\Container\Exception\LifetimeViolationException;
use Careminate\Container\Exception\ResolutionException;
use CareminateIntegration\Tests\Fixtures\Container\ConstructorService;
use CareminateIntegration\Tests\Fixtures\Container\LazyStateService;
use Closure;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;

final class LazySingletonLifecycleTest extends TestCase
{
    public function testMissingDependencyCanBeRegisteredBeforeInitializationRetry(): void
    {
        $container = new Container();

        $container->lazySingleton(
            'service',
            ConstructorService::class,
        );

        $service = $container->get('service');

        self::assertInstanceOf(ConstructorService::class, $service);

        $reflection = new ReflectionClass($service);

        self::assertTrue($reflection->isUninitializedLazyObject($service));

        $failure = self::captureResolutionFailure(
            static fn (): ContainerInterface => $service->dependency,
        );

        self::assertInstanceOf(
            NotFoundExceptionInterface::class,
            $failure->getPrevious(),
        );

        self::assertSame(
            ['service', ContainerInterface::class],
            $failure->dependencyPath(),
        );

        self::assertTrue($reflection->isUninitializedLazyObject($service));
        self::assertSame($service, $container->get('service'));

        $dependency = new Container();

        $container->register(ContainerInterface::class, $dependency);

        self::assertSame($dependency, $service->dependency);
        self::assertFalse($reflection->isUninitializedLazyObject($service));
        self::assertSame($service, $container->get('service'));

        self::assertSame(
            ['service', ContainerInterface::class],
            $failure->dependencyPath(),
        );
    }

    public function testTransientDependencyCannotHideScopedCaptureDuringInitialization(): void
    {
        $container = new Container();

        $container->scoped(
            'request.dependency',
            static fn (ContainerInterface $resolver): Container => new Container(),
        );

        $container->factory(
            'bridge',
            static fn (ContainerInterface $resolver): mixed => $resolver->get(
                'request.dependency',
            ),
        );

        $container->lazySingleton(
            'service',
            ConstructorService::class,
        );

        $container->bindContext(
            'service',
            ContainerInterface::class,
            'bridge',
        );

        $service = $container->get('service');

        self::assertInstanceOf(ConstructorService::class, $service);

        $container->runInScope(
            static function (ContainerInterface $resolver) use ($service): void {
                $requestDependency = $resolver->get('request.dependency');

                self::assertInstanceOf(Container::class, $requestDependency);

                $failure = self::captureResolutionFailure(
                    static fn (): ContainerInterface => $service->dependency,
                );

                self::assertSame(
                    ['service', 'bridge', 'request.dependency'],
                    $failure->dependencyPath(),
                );

                $bridgeFailure = $failure->getPrevious();

                self::assertInstanceOf(
                    ResolutionException::class,
                    $bridgeFailure,
                );

                self::assertInstanceOf(
                    LifetimeViolationException::class,
                    $bridgeFailure->getPrevious(),
                );

                self::assertSame(
                    $requestDependency,
                    $resolver->get('request.dependency'),
                );

                self::assertSame(
                    $requestDependency,
                    $resolver->get('bridge'),
                );
            },
        );

        $reflection = new ReflectionClass($service);

        self::assertTrue($reflection->isUninitializedLazyObject($service));

        $container->runInScope(
            static function (ContainerInterface $resolver): void {
                self::assertInstanceOf(
                    Container::class,
                    $resolver->get('request.dependency'),
                );
            },
        );
    }

    public function testCycleBetweenLazyServicesPreservesPathAndClearsBothGuards(): void
    {
        $container = new Container();

        $container->lazySingleton(
            'first',
            LazyStateService::class,
            arguments: [
                'count' => 1,
                'observer' => static function (
                    LazyStateService $service,
                ) use ($container): void {
                    $second = $container->get('second');

                    self::assertInstanceOf(LazyStateService::class, $second);
                    self::assertSame(2, $second->count);
                },
            ],
        );

        $container->lazySingleton(
            'second',
            LazyStateService::class,
            arguments: [
                'count' => 2,
                'observer' => static function (
                    LazyStateService $service,
                ) use ($container): void {
                    $container->get('first');
                },
            ],
        );

        $first = $container->get('first');
        $second = $container->get('second');

        self::assertInstanceOf(LazyStateService::class, $first);
        self::assertInstanceOf(LazyStateService::class, $second);

        $failure = self::captureResolutionFailure(
            static fn (): int => $first->count,
        );

        self::assertSame(
            ['first', 'second', 'first'],
            $failure->dependencyPath(),
        );

        $firstReflection = new ReflectionClass($first);
        $secondReflection = new ReflectionClass($second);

        self::assertTrue($firstReflection->isUninitializedLazyObject($first));
        self::assertTrue($secondReflection->isUninitializedLazyObject($second));

        self::assertSame($first, $container->get('first'));
        self::assertSame($second, $container->get('second'));

        // Initializing second independently only retrieves first's ghost.
        self::assertSame(2, $second->count);
        self::assertTrue($firstReflection->isUninitializedLazyObject($first));
        self::assertFalse($secondReflection->isUninitializedLazyObject($second));

        // First can now use the successfully initialized second service.
        self::assertSame(1, $first->count);
        self::assertFalse($firstReflection->isUninitializedLazyObject($first));

        self::assertSame(
            ['first', 'second', 'first'],
            $failure->dependencyPath(),
        );
    }

    public function testUninitializedSingletonCanOutliveItsFirstLookupScope(): void
    {
        $container = new Container();

        $container->lazySingleton(
            'service',
            LazyStateService::class,
            arguments: ['count' => 17],
        );

        $service = $container->runInScope(
            static fn (ContainerInterface $resolver): mixed => $resolver->get(
                'service',
            ),
        );

        self::assertInstanceOf(LazyStateService::class, $service);

        $reflection = new ReflectionClass($service);

        self::assertTrue($reflection->isUninitializedLazyObject($service));
        self::assertSame($service, $container->get('service'));
        self::assertSame(17, $service->count);
        self::assertFalse($reflection->isUninitializedLazyObject($service));

        $container->runInScope(
            static function (ContainerInterface $resolver) use ($service): void {
                self::assertSame($service, $resolver->get('service'));
                self::assertSame(17, $service->count);
            },
        );
    }

    /**
     * @param Closure(): mixed $operation
     */
    private static function captureResolutionFailure(
        Closure $operation,
    ): ResolutionException {
        try {
            $operation();
        } catch (ResolutionException $exception) {
            return $exception;
        }

        self::fail('The operation must throw a resolution exception.');
    }
}
