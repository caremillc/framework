<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use ArrayObject;
use Careminate\Container\Container;
use Careminate\Container\Exception\ContextBindingException;
use Careminate\Container\Exception\FrozenContainerException;
use Careminate\Container\Exception\LifetimeViolationException;
use Careminate\Container\Exception\ResolutionException;
use CareminateIntegration\Tests\Fixtures\Container\AttributedService;
use CareminateIntegration\Tests\Fixtures\Container\ConstructorService;
use CareminateIntegration\Tests\Fixtures\Container\LazyStateService;
use CareminateIntegration\Tests\Fixtures\Container\PropertylessLazyService;
use Closure;
use Error;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use stdClass;
use Throwable;
use TypeError;

final class LazySingletonTest extends TestCase
{
    public function testLookupsAliasesAndTagsShareAnUninitializedGhost(): void
    {
        $container = new Container();

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $container->lazySingleton(
            'service',
            LazyStateService::class,
            arguments: [
                'count' => 7,
                'observer' => static function (LazyStateService $service) use ($calls): void {
                    $calls->append(true);
                },
            ],
        );

        $container->alias('alias', 'service');
        $container->tag('group', 'alias');
        $container->freeze();

        self::assertCount(0, $calls);

        $service = $container->get('alias');

        self::assertInstanceOf(LazyStateService::class, $service);

        $reflection = new ReflectionClass(LazyStateService::class);

        self::assertTrue($reflection->isUninitializedLazyObject($service));
        self::assertSame($service, $container->get('service'));
        self::assertSame([$service], $container->tagged('group'));
        self::assertCount(0, $calls);

        self::assertSame('service', $service->kind());
        self::assertCount(0, $calls);

        self::assertSame(7, $service->count);
        self::assertCount(1, $calls);
        self::assertSame($service, $container->get('alias'));
    }

    public function testFirstLookupLocksContextBeforeInitialization(): void
    {
        $container = new Container();

        $container->register('target', new Container());
        $container->lazySingleton('service', ConstructorService::class);

        $service = $container->get('service');

        self::assertInstanceOf(ConstructorService::class, $service);

        $reflection = new ReflectionClass(ConstructorService::class);

        self::assertTrue($reflection->isUninitializedLazyObject($service));

        $this->expectException(ContextBindingException::class);

        $container->bindContext('service', ContainerInterface::class, 'target');
    }

    public function testAttributesContextAndExplicitCollectionsApplyAtInitialization(): void
    {
        $container = new Container();
        $dependency = new Container();

        $container->register('context.target', $dependency);
        $container->register('attribute.choice', 'choice');
        $container->register('attribute.optional', null);

        $container->lazySingleton(
            'service',
            AttributedService::class,
            arguments: ['choice' => 12],
            taggedArguments: ['handlers' => 'selected.handlers'],
        );

        $container->bindContext(
            'service',
            ContainerInterface::class,
            'context.target',
        );

        $service = $container->get('service');

        self::assertInstanceOf(AttributedService::class, $service);

        $container->register('handler', 'late handler');
        $container->tag('selected.handlers', 'handler');

        self::assertSame(['late handler'], $service->handlers);
        self::assertSame($dependency, $service->dependency);
        self::assertSame(12, $service->choice);
        self::assertNull($service->optional);
    }

    public function testDelayedInitializationRejectsCachedScopedDependencies(): void
    {
        $container = new Container();

        $container->scoped(
            'target',
            static fn (ContainerInterface $resolver): Container => new Container(),
        );

        $container->lazySingleton('service', ConstructorService::class);

        $container->bindContext(
            'service',
            ContainerInterface::class,
            'target',
        );

        $service = $container->get('service');

        self::assertInstanceOf(ConstructorService::class, $service);

        $container->runInScope(
            static function (ContainerInterface $resolver) use ($service): void {
                $target = $resolver->get('target');

                $failure = self::captureFailure(
                    static fn (): ContainerInterface => $service->dependency,
                );

                self::assertInstanceOf(ResolutionException::class, $failure);
                self::assertInstanceOf(
                    LifetimeViolationException::class,
                    $failure->getPrevious(),
                );

                self::assertSame(
                    ['service', 'target'],
                    $failure->dependencyPath(),
                );

                self::assertSame($target, $resolver->get('target'));
            },
        );
    }

    public function testRecursiveAliasLookupCannotReturnTheInitializingGhost(): void
    {
        $container = new Container();

        $container->lazySingleton(
            'service',
            LazyStateService::class,
            arguments: [
                'count' => 3,
                'observer' => static function (LazyStateService $service) use ($container): void {
                    $container->get('alias');
                },
            ],
        );

        $container->alias('alias', 'service');
        $container->register('healthy', 'healthy');

        $service = $container->get('service');

        self::assertInstanceOf(LazyStateService::class, $service);

        $failure = self::captureFailure(
            static fn (): int => $service->count,
        );

        self::assertInstanceOf(ResolutionException::class, $failure);

        self::assertSame(
            ['service', 'alias'],
            $failure->dependencyPath(),
        );

        self::assertSame($service, $container->get('service'));
        self::assertSame('healthy', $container->get('healthy'));

        $reflection = new ReflectionClass(LazyStateService::class);

        self::assertTrue($reflection->isUninitializedLazyObject($service));
    }

    public function testFailedInitializationRetriesOnTheSameCachedGhost(): void
    {
        $container = new Container();
        $original = new Error('First initialization failed.');

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $container->lazySingleton(
            'service',
            LazyStateService::class,
            arguments: [
                'count' => 9,
                'observer' => static function (LazyStateService $service) use (
                    $calls,
                    $original,
                ): void {
                    $calls->append(true);

                    if ($calls->count() === 1) {
                        throw $original;
                    }
                },
            ],
        );

        $service = $container->get('service');

        self::assertInstanceOf(LazyStateService::class, $service);

        $failure = self::captureFailure(
            static fn (): int => $service->count,
        );

        self::assertInstanceOf(ResolutionException::class, $failure);
        self::assertSame($original, $failure->getPrevious());
        self::assertSame(['service'], $failure->dependencyPath());
        self::assertSame($service, $container->get('service'));

        self::assertSame(9, $service->count);
        self::assertCount(2, $calls);
        self::assertSame($service, $container->get('service'));
    }

    public function testStrictConstructorErrorsAreWrappedAtInitialization(): void
    {
        $container = new Container();

        $container->lazySingleton(
            'service',
            LazyStateService::class,
            arguments: ['count' => '7'],
        );

        $service = $container->get('service');

        self::assertInstanceOf(LazyStateService::class, $service);

        $failure = self::captureFailure(
            static fn (): int => $service->count,
        );

        self::assertInstanceOf(ResolutionException::class, $failure);
        self::assertInstanceOf(TypeError::class, $failure->getPrevious());
        self::assertSame(['service'], $failure->dependencyPath());
    }

    public function testCloningAndSerializationDoNotReplaceTheCachedGhost(): void
    {
        $container = new Container();

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $container->lazySingleton(
            'service',
            LazyStateService::class,
            arguments: [
                'count' => 5,
                'observer' => static function (LazyStateService $service) use ($calls): void {
                    $calls->append(true);
                },
            ],
        );

        $service = $container->get('service');

        self::assertInstanceOf(LazyStateService::class, $service);

        $copy = clone $service;
        $serialized = serialize($service);

        self::assertNotSame($service, $copy);
        self::assertSame(5, $copy->count);
        self::assertNotSame('', $serialized);
        self::assertSame($service, $container->get('service'));
        self::assertCount(1, $calls);
    }

    public function testUnsupportedClassDoesNotReserveTheIdentifier(): void
    {
        $container = new Container();

        $failure = self::captureFailure(
            static function () use ($container): void {
                $container->lazySingleton(
                    'service',
                    PropertylessLazyService::class,
                );
            },
        );

        self::assertInstanceOf(ResolutionException::class, $failure);
        self::assertInstanceOf(
            InvalidArgumentException::class,
            $failure->getPrevious(),
        );

        self::assertFalse($container->has('service'));

        $container->register('service', new stdClass());

        self::assertInstanceOf(stdClass::class, $container->get('service'));
    }

    public function testFrozenContainerRejectsLazyRegistration(): void
    {
        $container = new Container();
        $container->freeze();

        $this->expectException(FrozenContainerException::class);

        $container->lazySingleton(
            'service',
            LazyStateService::class,
            arguments: ['count' => 1],
        );
    }

    /**
     * @param Closure(): mixed $operation
     */
    private static function captureFailure(Closure $operation): Throwable
    {
        try {
            $operation();
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('The operation must throw an exception.');
    }
}
