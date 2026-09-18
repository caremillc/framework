<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container\Compilation;

use ArrayObject;
use Careminate\Container\Compilation\Internal\ConstructorPlanCompiler;
use Careminate\Container\Container;
use Careminate\Container\Exception\ContextBindingException;
use Careminate\Container\Exception\DuplicateEntryException;
use Careminate\Container\Exception\EntryNotFoundException;
use Careminate\Container\Exception\FrozenContainerException;
use Careminate\Container\Exception\InvalidEntryIdentifierException;
use Careminate\Container\Exception\LifetimeViolationException;
use Careminate\Container\Exception\ResolutionException;
use Careminate\Container\Internal\ConstructorMetadata;
use Careminate\Container\Internal\LazyClass;
use CareminateIntegration\Tests\Fixtures\Container\DefinitionConsumer;
use CareminateIntegration\Tests\Fixtures\Container\DefinitionDependency;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;
use TypeError;

final class PreparedLazyContainerTest extends TestCase
{
    public function testPreparedArgumentsAreDeferredAndSingletonIdentityIsPreserved(): void
    {
        $container = new Container();

        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $prepared = (new ConstructorPlanCompiler())->compile(
            new ConstructorMetadata(
                DefinitionDependency::class,
                ['label' => 'prepared'],
            ),
        );

        $container->preparedLazySingleton(
            'service',
            $prepared->className,
            static function (ContainerInterface $resolver) use (
                $container,
                $prepared,
                $calls,
            ): array {
                $calls->append(true);

                return $prepared->resolveArguments(
                    $resolver,
                    $container->tagged(...),
                );
            },
        );

        $container->alias('alias', 'service');
        $container->freeze();

        self::assertTrue($container->has('service'));
        self::assertCount(0, $calls);

        $service = $container->get('alias');

        self::assertInstanceOf(DefinitionDependency::class, $service);
        self::assertSame($service, $container->get('service'));

        $lazyClass = new LazyClass(DefinitionDependency::class);

        self::assertTrue($lazyClass->isUninitialized($service));
        self::assertCount(0, $calls);

        self::assertSame('prepared', $service->label);
        self::assertFalse($lazyClass->isUninitialized($service));
        self::assertCount(1, $calls);

        self::assertSame($service, $container->get('alias'));
        self::assertCount(1, $calls);
    }

    public function testMissingDependencyCanBeRegisteredBeforeRetry(): void
    {
        $container = new Container();

        self::registerPreparedConsumer($container);

        $service = $container->get('consumer');

        self::assertInstanceOf(DefinitionConsumer::class, $service);

        $failure = self::captureFailure(
            static fn (): DefinitionDependency => $service->dependency,
        );

        self::assertInstanceOf(
            EntryNotFoundException::class,
            $failure->getPrevious(),
        );

        self::assertSame(
            ['consumer', DefinitionDependency::class],
            $failure->dependencyPath(),
        );

        $lazyClass = new LazyClass(DefinitionConsumer::class);

        self::assertTrue($lazyClass->isUninitialized($service));
        self::assertSame($service, $container->get('consumer'));

        $dependency = new DefinitionDependency('available');

        $container->register(DefinitionDependency::class, $dependency);

        self::assertSame($dependency, $service->dependency);
        self::assertFalse($lazyClass->isUninitialized($service));
        self::assertSame($service, $container->get('consumer'));

        self::assertSame(
            'clean',
            $container->runInScope(
                static fn (ContainerInterface $resolver): string => 'clean',
            ),
        );
    }

    public function testCircularAliasLookupIsRejectedAndInitializationCanRetry(): void
    {
        $container = new Container();

        /** @var ArrayObject<int, bool> $attempts */
        $attempts = new ArrayObject();

        $container->preparedLazySingleton(
            'service',
            DefinitionDependency::class,
            static function (ContainerInterface $resolver) use (
                $attempts,
            ): array {
                $attempts->append(true);

                if ($attempts->count() === 1) {
                    $resolver->get('alias');
                }

                return ['label' => 'recovered'];
            },
        );

        $container->alias('alias', 'service');

        $service = $container->get('service');

        self::assertInstanceOf(DefinitionDependency::class, $service);

        $failure = self::captureFailure(
            static fn (): string => $service->label,
        );

        self::assertSame(
            ['service', 'alias'],
            $failure->dependencyPath(),
        );

        $previous = $failure->getPrevious();

        self::assertInstanceOf(ResolutionException::class, $previous);
        self::assertSame(
            'A circular lazy service dependency was detected.',
            $previous->getMessage(),
        );

        $lazyClass = new LazyClass(DefinitionDependency::class);

        self::assertTrue($lazyClass->isUninitialized($service));
        self::assertSame($service, $container->get('alias'));

        self::assertSame('recovered', $service->label);
        self::assertFalse($lazyClass->isUninitialized($service));
        self::assertCount(2, $attempts);
    }

    public function testScopedCaptureIsRejectedAndScopeRemainsUsable(): void
    {
        $container = new Container();

        $container->scoped(
            DefinitionDependency::class,
            static fn (ContainerInterface $resolver): DefinitionDependency =>
                new DefinitionDependency('scoped'),
        );

        self::registerPreparedConsumer($container);

        $service = $container->get('consumer');

        self::assertInstanceOf(DefinitionConsumer::class, $service);

        $container->runInScope(
            static function (ContainerInterface $resolver) use (
                $service,
            ): null {
                $failure = self::captureFailure(
                    static fn (): DefinitionDependency => $service->dependency,
                );

                self::assertInstanceOf(
                    LifetimeViolationException::class,
                    $failure->getPrevious(),
                );

                self::assertSame(
                    ['consumer', DefinitionDependency::class],
                    $failure->dependencyPath(),
                );

                $dependency = $resolver->get(DefinitionDependency::class);

                self::assertInstanceOf(
                    DefinitionDependency::class,
                    $dependency,
                );

                self::assertSame(
                    $dependency,
                    $resolver->get(DefinitionDependency::class),
                );

                return null;
            },
        );

        $lazyClass = new LazyClass(DefinitionConsumer::class);

        self::assertTrue($lazyClass->isUninitialized($service));

        self::assertSame(
            'another scope',
            $container->runInScope(
                static fn (ContainerInterface $resolver): string =>
                    'another scope',
            ),
        );
    }

    public function testStrictConstructorFailurePreservesCauseAndAllowsRetry(): void
    {
        $container = new Container();

        /** @var ArrayObject<int, bool> $attempts */
        $attempts = new ArrayObject();

        $container->preparedLazySingleton(
            'service',
            DefinitionDependency::class,
            static function (ContainerInterface $resolver) use (
                $attempts,
            ): array {
                $attempts->append(true);

                return [
                    'label' => $attempts->count() === 1 ? 123 : 'corrected',
                ];
            },
        );

        $service = $container->get('service');

        self::assertInstanceOf(DefinitionDependency::class, $service);

        $failure = self::captureFailure(
            static fn (): string => $service->label,
        );

        self::assertInstanceOf(TypeError::class, $failure->getPrevious());
        self::assertSame(['service'], $failure->dependencyPath());

        $lazyClass = new LazyClass(DefinitionDependency::class);

        self::assertTrue($lazyClass->isUninitialized($service));
        self::assertSame($service, $container->get('service'));

        self::assertSame('corrected', $service->label);
        self::assertFalse($lazyClass->isUninitialized($service));
        self::assertCount(2, $attempts);
    }

    public function testInvalidClassDoesNotReserveIdentifier(): void
    {
        $container = new Container();

        $failure = self::captureFailure(
            static function () use ($container): null {
                $container->preparedLazySingleton(
                    'service',
                    stdClass::class,
                    static fn (ContainerInterface $resolver): array => [],
                );

                return null;
            },
        );

        self::assertSame(
            'The prepared lazy entry could not be registered.',
            $failure->getMessage(),
        );

        self::assertInstanceOf(
            InvalidArgumentException::class,
            $failure->getPrevious(),
        );

        self::assertFalse($container->has('service'));

        $container->register('service', 'replacement');

        self::assertSame('replacement', $container->get('service'));
    }

    public function testDuplicateRegistrationPreservesExistingEntry(): void
    {
        $container = new Container();
        $container->register('service', null);

        try {
            $container->preparedLazySingleton(
                'service',
                DefinitionDependency::class,
                static fn (ContainerInterface $resolver): array => [],
            );
        } catch (DuplicateEntryException) {
            self::assertTrue($container->has('service'));
            self::assertNull($container->get('service'));

            return;
        }

        self::fail(
            'Prepared lazy registration must reject duplicate identifiers.',
        );
    }

    public function testFrozenContainerRejectsPreparedRegistration(): void
    {
        $container = new Container();
        $container->freeze();

        $this->expectException(FrozenContainerException::class);

        $container->preparedLazySingleton(
            'service',
            DefinitionDependency::class,
            static fn (ContainerInterface $resolver): array => [],
        );
    }

    public function testEmptyIdentifierIsRejected(): void
    {
        $container = new Container();

        $this->expectException(InvalidEntryIdentifierException::class);

        $container->preparedLazySingleton(
            '',
            DefinitionDependency::class,
            static fn (ContainerInterface $resolver): array => [],
        );
    }

    public function testPreparedRegistrationRejectsRuntimeContextMutation(): void
    {
        $container = new Container();

        self::registerPreparedConsumer($container);

        $container->register('target', new DefinitionDependency());

        $this->expectException(ContextBindingException::class);

        $container->bindContext(
            'consumer',
            DefinitionDependency::class,
            'target',
        );
    }

    private static function registerPreparedConsumer(Container $container): void
    {
        $prepared = (new ConstructorPlanCompiler())->compile(
            new ConstructorMetadata(DefinitionConsumer::class),
        );

        $container->preparedLazySingleton(
            'consumer',
            $prepared->className,
            static fn (ContainerInterface $resolver): array =>
                $prepared->resolveArguments(
                    $resolver,
                    $container->tagged(...),
                ),
        );
    }

    /**
     * @param Closure(): mixed $operation
     */
    private static function captureFailure(
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
