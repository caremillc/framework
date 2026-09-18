<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use ArrayObject;
use Careminate\Container\Container;
use Careminate\Container\DeferredService;
use Careminate\Container\Exception\ContextBindingException;
use Careminate\Container\Exception\EntryNotFoundException;
use Careminate\Container\Exception\InvalidEntryIdentifierException;
use Careminate\Container\Exception\LifetimeViolationException;
use Careminate\Container\Exception\ResolutionException;
use Careminate\Container\Exception\ScopeStateException;
use CareminateIntegration\Tests\Fixtures\Container\ConstructorService;
use Closure;
use Error;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;
use Throwable;

final class DeferredServiceTest extends TestCase
{
    public function testReferenceCreationIsLazyAndPreservesTransientLifetime(): void
    {
        $container = new Container();

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $container->factory(
            'service',
            static function (ContainerInterface $resolver) use ($calls): stdClass {
                $calls->append(true);

                return new stdClass();
            },
        );

        $container->freeze();
        $reference = $container->defer('service');

        self::assertCount(0, $calls);

        $first = $reference->get();
        $second = $reference->get();

        self::assertInstanceOf(stdClass::class, $first);
        self::assertInstanceOf(stdClass::class, $second);
        self::assertNotSame($first, $second);
        self::assertCount(2, $calls);
    }

    public function testReferencesPreserveSingletonAndRegisteredValues(): void
    {
        $container = new Container();

        $container->singleton(
            'service',
            static fn (ContainerInterface $resolver): stdClass => new stdClass(),
        );

        $container->register('null', null);
        $container->alias('alias', 'service');

        $reference = $container->defer('alias');
        $service = $reference->get();

        self::assertInstanceOf(stdClass::class, $service);
        self::assertSame($service, $reference->get());
        self::assertSame($service, $container->get('service'));
        self::assertNull($container->defer('null')->get());
    }

    public function testMissingTargetIsRejectedImmediately(): void
    {
        $container = new Container();

        $this->expectException(EntryNotFoundException::class);

        $container->defer('missing');
    }

    public function testEmptyIdentifierIsRejectedImmediately(): void
    {
        $container = new Container();

        $this->expectException(InvalidEntryIdentifierException::class);

        $container->defer('');
    }

    public function testCreationDoesNotLockContextualBindingsButResolutionDoes(): void
    {
        $container = new Container();
        $dependency = new Container();

        $container->autowire('service', ConstructorService::class);
        $container->register('target', $dependency);
        $container->register('alternative', new Container());

        $reference = $container->defer('service');

        $container->bindContext('service', ContainerInterface::class, 'target');

        $service = $reference->get();

        self::assertInstanceOf(ConstructorService::class, $service);
        self::assertSame($dependency, $service->dependency);

        $this->expectException(ContextBindingException::class);

        $container->bindContext(
            'service',
            ContainerInterface::class,
            'alternative',
        );
    }

    public function testScopedReferenceRequiresAnActiveScope(): void
    {
        $container = new Container();
        $container->scopedAutowire('service', stdClass::class);
        $container->alias('alias', 'service');

        $this->expectException(ScopeStateException::class);

        $container->defer('alias');
    }

    public function testScopedReferenceExpiresAndCannotReactivate(): void
    {
        $container = new Container();
        $container->scopedAutowire('service', stdClass::class);

        $reference = $container->runInScope(
            static function (ContainerInterface $resolver) use ($container): DeferredService {
                $reference = $container->defer('service');

                self::assertSame($resolver->get('service'), $reference->get());

                return $reference;
            },
        );

        self::assertInstanceOf(DeferredService::class, $reference);

        $failure = self::captureFailure(
            static fn (): mixed => $reference->get(),
        );

        self::assertInstanceOf(ScopeStateException::class, $failure);
        self::assertSame(['service'], $failure->dependencyPath());

        $container->runInScope(
            static function (ContainerInterface $resolver) use (
                $container,
                $reference,
            ): void {
                $failure = self::captureFailure(
                    static fn (): mixed => $reference->get(),
                );

                self::assertInstanceOf(ScopeStateException::class, $failure);

                self::assertSame(
                    $resolver->get('service'),
                    $container->defer('service')->get(),
                );
            },
        );
    }

    public function testScopeBoundReferenceToOrdinaryValueAlsoExpires(): void
    {
        $container = new Container();
        $container->register('value', 'value');

        $reference = $container->runInScope(
            static fn (ContainerInterface $resolver): DeferredService =>
                $container->defer('value'),
        );

        self::assertInstanceOf(DeferredService::class, $reference);

        $this->expectException(ScopeStateException::class);

        $reference->get();
    }

    public function testCallbackFailureExpiresReferencesCreatedInsideItsScope(): void
    {
        $container = new Container();
        $container->register('value', 'value');
        $original = new Error('Operation failed.');

        /** @var ArrayObject<int, DeferredService> $references */
        $references = new ArrayObject();

        $failure = self::captureFailure(
            static fn (): mixed => $container->runInScope(
                static function (ContainerInterface $resolver) use (
                    $container,
                    $references,
                    $original,
                ): never {
                    $references->append($container->defer('value'));

                    throw $original;
                },
            ),
        );

        self::assertSame($original, $failure);
        self::assertCount(1, $references);

        $reference = $references[0];

        self::assertInstanceOf(DeferredService::class, $reference);

        $expired = self::captureFailure(
            static fn (): mixed => $reference->get(),
        );

        self::assertInstanceOf(ScopeStateException::class, $expired);
    }

    public function testSingletonCannotCreateADirectScopedReference(): void
    {
        $container = new Container();
        $container->scopedAutowire('scoped', stdClass::class);

        $container->singleton(
            'owner',
            static fn (ContainerInterface $resolver): DeferredService =>
                $container->defer('scoped'),
        );

        $container->runInScope(
            static function (ContainerInterface $resolver): void {
                $failure = self::captureFailure(
                    static fn (): mixed => $resolver->get('owner'),
                );

                self::assertInstanceOf(ResolutionException::class, $failure);
                self::assertInstanceOf(
                    LifetimeViolationException::class,
                    $failure->getPrevious(),
                );
            },
        );
    }

    public function testDelayedResolutionRetainsSingletonRestrictions(): void
    {
        $container = new Container();
        $container->scopedAutowire('scoped', stdClass::class);

        $container->factory(
            'bridge',
            static fn (ContainerInterface $resolver): mixed => $resolver->get(
                'scoped',
            ),
        );

        $container->singleton(
            'owner',
            static fn (ContainerInterface $resolver): DeferredService =>
                $container->defer('bridge'),
        );

        $reference = $container->get('owner');

        self::assertInstanceOf(DeferredService::class, $reference);

        $container->runInScope(
            static function (ContainerInterface $resolver) use ($reference): void {
                $scoped = $resolver->get('scoped');

                $failure = self::captureFailure(
                    static fn (): mixed => $reference->get(),
                );

                self::assertInstanceOf(ResolutionException::class, $failure);
                self::assertInstanceOf(
                    LifetimeViolationException::class,
                    $failure->getPrevious(),
                );

                self::assertSame(['bridge', 'scoped'], $failure->dependencyPath());

                self::assertSame($scoped, $resolver->get('scoped'));
            },
        );
    }

    public function testRestrictionsPropagateToFurtherDeferredReferences(): void
    {
        $container = new Container();
        $container->scopedAutowire('scoped', stdClass::class);

        $container->factory(
            'bridge',
            static fn (ContainerInterface $resolver): mixed => $resolver->get(
                'scoped',
            ),
        );

        $container->factory(
            'reference.factory',
            static fn (ContainerInterface $resolver): DeferredService =>
                $container->defer('bridge'),
        );

        $container->singleton(
            'owner',
            static fn (ContainerInterface $resolver): DeferredService =>
                $container->defer('reference.factory'),
        );

        $outer = $container->get('owner');

        self::assertInstanceOf(DeferredService::class, $outer);

        $inner = $outer->get();

        self::assertInstanceOf(DeferredService::class, $inner);

        $container->runInScope(
            static function (ContainerInterface $resolver) use ($inner): void {
                $failure = self::captureFailure(
                    static fn (): mixed => $inner->get(),
                );

                self::assertInstanceOf(ResolutionException::class, $failure);
                self::assertInstanceOf(
                    LifetimeViolationException::class,
                    $failure->getPrevious(),
                );
            },
        );
    }

    public function testFailedDeferredSingletonCanRetry(): void
    {
        $container = new Container();
        $original = new Error('First construction failed.');

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $container->singleton(
            'service',
            static function (ContainerInterface $resolver) use (
                $calls,
                $original,
            ): stdClass {
                $calls->append(true);

                if ($calls->count() === 1) {
                    throw $original;
                }

                return new stdClass();
            },
        );

        $container->alias('alias', 'service');
        $reference = $container->defer('alias');

        $failure = self::captureFailure(
            static fn (): mixed => $reference->get(),
        );

        self::assertInstanceOf(ResolutionException::class, $failure);
        self::assertSame($original, $failure->getPrevious());
        self::assertSame(['alias'], $failure->dependencyPath());

        $service = $reference->get();

        self::assertInstanceOf(stdClass::class, $service);
        self::assertSame($service, $reference->get());
        self::assertCount(2, $calls);
    }

    public function testDeferredResolutionParticipatesInCycleDetection(): void
    {
        $container = new Container();

        $container->factory(
            'service',
            static fn (ContainerInterface $resolver): mixed =>
                $container->defer('service')->get(),
        );

        $failure = self::captureFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertInstanceOf(ResolutionException::class, $failure);
        self::assertSame(['service', 'service'], $failure->dependencyPath());
    }

    public function testReferencesCannotBeSerialized(): void
    {
        $container = new Container();
        $container->register('value', 'value');
        $reference = $container->defer('value');

        $this->expectException(LogicException::class);

        serialize($reference);
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
