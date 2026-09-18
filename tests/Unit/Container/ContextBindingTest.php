<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container;

use Careminate\Container\Container;
use Careminate\Container\Exception\ContextBindingException;
use Careminate\Container\Exception\EntryNotFoundException;
use Careminate\Container\Exception\FrozenContainerException;
use Careminate\Container\Exception\LifetimeViolationException;
use Careminate\Container\Exception\ResolutionException;
use CareminateIntegration\Tests\Fixtures\Container\ConstructorService;
use Closure;
use Error;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;
use Throwable;
use TypeError;

final class ContextBindingTest extends TestCase
{
    public function testBindingsBelongToConsumerRegistrations(): void
    {
        $container = new Container();
        $global = new Container();
        $special = new Container();

        $container->register(ContainerInterface::class, $global);
        $container->register('special', $special);

        $container->autowire('first', ConstructorService::class);
        $container->autowire('second', ConstructorService::class);

        $container->bindContext('first', ContainerInterface::class, 'special');

        $first = $container->get('first');
        $second = $container->get('second');

        self::assertInstanceOf(ConstructorService::class, $first);
        self::assertInstanceOf(ConstructorService::class, $second);
        self::assertSame($special, $first->dependency);
        self::assertSame($global, $second->dependency);
        self::assertSame($global, $container->get(ContainerInterface::class));
    }

    public function testConsumerAndTargetAliasesAreSupported(): void
    {
        $container = new Container();
        $dependency = new Container();

        $container->register('target', $dependency);
        $container->alias('target.alias', 'target');

        $container->autowire('service', ConstructorService::class, shared: true);
        $container->alias('service.alias', 'service');

        $container->bindContext(
            'service.alias',
            ContainerInterface::class,
            'target.alias',
        );

        $service = $container->get('service.alias');

        self::assertInstanceOf(ConstructorService::class, $service);
        self::assertSame($dependency, $service->dependency);
        self::assertSame($service, $container->get('service'));
    }

    public function testExplicitArgumentBypassesContextualTarget(): void
    {
        $container = new Container();
        $explicit = new Container();

        $container->factory(
            'target',
            static function (ContainerInterface $resolver): never {
                throw new Error('The overridden target must not be resolved.');
            },
        );

        $container->autowire(
            'service',
            ConstructorService::class,
            arguments: ['dependency' => $explicit],
        );

        $container->bindContext('service', ContainerInterface::class, 'target');

        $service = $container->get('service');

        self::assertInstanceOf(ConstructorService::class, $service);
        self::assertSame($explicit, $service->dependency);
    }

    public function testContextualBindingOverridesAnOptionalDefault(): void
    {
        $container = new Container();
        $optional = new stdClass();

        $container->register(ContainerInterface::class, new Container());
        $container->register('optional.target', $optional);
        $container->autowire('service', ConstructorService::class);

        $container->bindContext('service', stdClass::class, 'optional.target');

        $service = $container->get('service');

        self::assertInstanceOf(ConstructorService::class, $service);
        self::assertSame($optional, $service->optional);
    }

    public function testInvalidTargetValueDoesNotFallBackToDefault(): void
    {
        $container = new Container();

        $container->register(ContainerInterface::class, new Container());
        $container->register('invalid', 'not an object');
        $container->autowire('service', ConstructorService::class);
        $container->bindContext('service', stdClass::class, 'invalid');

        $failure = self::captureFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertInstanceOf(ResolutionException::class, $failure);
        self::assertInstanceOf(TypeError::class, $failure->getPrevious());
    }

    public function testDuplicateBindingDoesNotReplaceTheOriginal(): void
    {
        $container = new Container();
        $original = new Container();

        $container->register('original', $original);
        $container->register('replacement', new Container());

        $container->autowire('service', ConstructorService::class);
        $container->alias('alias', 'service');

        $container->bindContext('service', ContainerInterface::class, 'original');

        $failure = self::captureFailure(
            static function () use ($container): void {
                $container->bindContext(
                    'alias',
                    ContainerInterface::class,
                    'replacement',
                );
            },
        );

        self::assertInstanceOf(ContextBindingException::class, $failure);

        $service = $container->get('service');

        self::assertInstanceOf(ConstructorService::class, $service);
        self::assertSame($original, $service->dependency);
    }

    public function testMissingTargetDoesNotReserveTheBinding(): void
    {
        $container = new Container();
        $target = new Container();

        $container->autowire('service', ConstructorService::class);

        $failure = self::captureFailure(
            static function () use ($container): void {
                $container->bindContext(
                    'service',
                    ContainerInterface::class,
                    'target',
                );
            },
        );

        self::assertInstanceOf(EntryNotFoundException::class, $failure);

        $container->register('target', $target);
        $container->bindContext('service', ContainerInterface::class, 'target');

        $service = $container->get('service');

        self::assertInstanceOf(ConstructorService::class, $service);
        self::assertSame($target, $service->dependency);
    }

    public function testOrdinaryFactoriesCannotReceiveConstructorBindings(): void
    {
        $container = new Container();

        $container->factory(
            'service',
            static fn (ContainerInterface $resolver): stdClass => new stdClass(),
        );

        $container->register('target', new Container());

        $this->expectException(ContextBindingException::class);

        $container->bindContext('service', ContainerInterface::class, 'target');
    }

    public function testUnknownConstructorDependencyIsRejected(): void
    {
        $container = new Container();

        $container->autowire('service', ConstructorService::class);
        $container->register('target', new Container());

        $this->expectException(ContextBindingException::class);

        $container->bindContext('service', 'UnknownDependency', 'target');
    }

    public function testSuccessfulLookupLocksTheCanonicalConsumer(): void
    {
        $container = new Container();

        $container->register(ContainerInterface::class, new Container());
        $container->register('target', new Container());

        $container->autowire('service', ConstructorService::class);
        $container->alias('alias', 'service');

        $container->get('alias');

        $this->expectException(ContextBindingException::class);

        $container->bindContext('service', ContainerInterface::class, 'target');
    }

    public function testFailedLookupAlsoLocksContextualRegistration(): void
    {
        $container = new Container();

        $container->autowire('service', ConstructorService::class);
        $container->register('target', new Container());

        $failure = self::captureFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertInstanceOf(ResolutionException::class, $failure);

        $this->expectException(ContextBindingException::class);

        $container->bindContext('service', ContainerInterface::class, 'target');
    }

    public function testFreezeBlocksContextualRegistration(): void
    {
        $container = new Container();

        $container->autowire('service', ConstructorService::class);
        $container->register('target', new Container());
        $container->freeze();

        $this->expectException(FrozenContainerException::class);

        $container->bindContext('service', ContainerInterface::class, 'target');
    }

    public function testContextDoesNotChangeLookupsInsideTargetFactories(): void
    {
        $container = new Container();
        $global = new Container();

        $container->register(ContainerInterface::class, $global);

        $container->factory(
            'target',
            static fn (ContainerInterface $resolver): mixed => $resolver->get(
                ContainerInterface::class,
            ),
        );

        $container->autowire('service', ConstructorService::class);
        $container->bindContext('service', ContainerInterface::class, 'target');

        $service = $container->get('service');

        self::assertInstanceOf(ConstructorService::class, $service);
        self::assertSame($global, $service->dependency);
    }

    public function testContextualCyclePreservesSelectedTargetPath(): void
    {
        $container = new Container();

        $container->autowire('service', ConstructorService::class);
        $container->alias('service.alias', 'service');

        $container->bindContext(
            'service',
            ContainerInterface::class,
            'service.alias',
        );

        $failure = self::captureFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertInstanceOf(ResolutionException::class, $failure);

        self::assertSame(
            ['service', 'service.alias'],
            $failure->dependencyPath(),
        );
    }

    public function testScopedConsumerCanResolveAContextualScopedTarget(): void
    {
        $container = new Container();

        $container->scoped(
            'target',
            static fn (ContainerInterface $resolver): Container => new Container(),
        );

        $container->scopedAutowire('service', ConstructorService::class);
        $container->bindContext('service', ContainerInterface::class, 'target');
        $container->freeze();

        $first = $container->runInScope(
            static function (ContainerInterface $resolver): mixed {
                $service = $resolver->get('service');

                self::assertInstanceOf(ConstructorService::class, $service);
                self::assertSame($resolver->get('target'), $service->dependency);

                return $service;
            },
        );

        $second = $container->runInScope(
            static fn (ContainerInterface $resolver): mixed => $resolver->get(
                'service',
            ),
        );

        self::assertInstanceOf(ConstructorService::class, $first);
        self::assertInstanceOf(ConstructorService::class, $second);
        self::assertNotSame($first->dependency, $second->dependency);
    }

    public function testSingletonCannotAcquireCachedContextualScopedTarget(): void
    {
        $container = new Container();

        $container->scoped(
            'target',
            static fn (ContainerInterface $resolver): Container => new Container(),
        );

        $container->autowire('service', ConstructorService::class, shared: true);
        $container->bindContext('service', ContainerInterface::class, 'target');

        $container->runInScope(
            static function (ContainerInterface $resolver): void {
                $resolver->get('target');

                $failure = self::captureFailure(
                    static fn (): mixed => $resolver->get('service'),
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
            },
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
