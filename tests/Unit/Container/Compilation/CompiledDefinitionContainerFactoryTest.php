<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container\Compilation;

use Careminate\Container\Compilation\CompiledDefinitionContainerFactory;
use Careminate\Container\Compilation\DefinitionBuilder;
use Careminate\Container\Compilation\DefinitionContainerFactory;
use Careminate\Container\Compilation\DefinitionLifetime;
use Careminate\Container\Compilation\DefinitionSet;
use Careminate\Container\Compilation\Exception\DefinitionException;
use Careminate\Container\Compilation\ServiceDefinition;
use Careminate\Container\Container;
use Careminate\Container\Exception\EntryNotFoundException;
use Careminate\Container\Exception\FrozenContainerException;
use Careminate\Container\Exception\LifetimeViolationException;
use Careminate\Container\Exception\ResolutionException;
use Careminate\Container\Internal\LazyClass;
use CareminateIntegration\Tests\Fixtures\Container\DefinitionConsumer;
use CareminateIntegration\Tests\Fixtures\Container\DefinitionDependency;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;
use TypeError;

final class CompiledDefinitionContainerFactoryTest extends TestCase
{
    public function testEmptyDefinitionsProduceAFrozenContainer(): void
    {
        $container = new CompiledDefinitionContainerFactory()->create(
            new DefinitionBuilder()->build(),
        );

        self::assertTrue($container->isFrozen());

        $this->expectException(FrozenContainerException::class);

        $container->register('late', null);
    }

    #[DataProvider('factoryModes')]
    public function testTransientAndSingletonLifetimesMatch(
        bool $compiled,
    ): void {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'transient',
            DefinitionDependency::class,
        ));
        $builder->add(ServiceDefinition::forAutowire(
            'singleton',
            DefinitionDependency::class,
            lifetime: DefinitionLifetime::Singleton,
        ));
        $builder->alias('alias', 'singleton');

        $container = $this->create($builder->build(), $compiled);

        $first = $container->get('transient');
        $second = $container->get('transient');

        self::assertInstanceOf(DefinitionDependency::class, $first);
        self::assertInstanceOf(DefinitionDependency::class, $second);
        self::assertNotSame($first, $second);

        $singleton = $container->get('singleton');

        self::assertInstanceOf(DefinitionDependency::class, $singleton);
        self::assertSame($singleton, $container->get('singleton'));
        self::assertSame($singleton, $container->get('alias'));
    }

    #[DataProvider('factoryModes')]
    public function testScopedLifetimeMatches(bool $compiled): void
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'scoped',
            DefinitionDependency::class,
            lifetime: DefinitionLifetime::Scoped,
        ));

        $container = $this->create($builder->build(), $compiled);

        $operation = static function (ContainerInterface $resolver): mixed {
            $service = $resolver->get('scoped');

            self::assertSame($service, $resolver->get('scoped'));

            return $service;
        };

        $first = $container->runInScope($operation);
        $second = $container->runInScope($operation);

        self::assertInstanceOf(DefinitionDependency::class, $first);
        self::assertInstanceOf(DefinitionDependency::class, $second);
        self::assertNotSame($first, $second);
    }

    #[DataProvider('factoryModes')]
    public function testContextTagsAndLiteralsMatch(bool $compiled): void
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'consumer',
            DefinitionConsumer::class,
            arguments: ['label' => 'configured'],
            taggedArguments: ['handlers' => 'handlers'],
        ));
        $builder->add(ServiceDefinition::forAutowire(
            'dependency',
            DefinitionDependency::class,
            lifetime: DefinitionLifetime::Singleton,
            arguments: ['label' => 'contextual'],
        ));
        $builder->add(ServiceDefinition::forValue('first', null));
        $builder->add(ServiceDefinition::forValue('second', 'handled'));

        $builder->alias('consumer.alias', 'consumer');
        $builder->alias('dependency.alias', 'dependency');
        $builder->tag('handlers', 'first', 'second');
        $builder->bindContext(
            'consumer.alias',
            DefinitionDependency::class,
            'dependency.alias',
        );

        $container = $this->create($builder->build(), $compiled);
        $consumer = $container->get('consumer.alias');

        self::assertInstanceOf(DefinitionConsumer::class, $consumer);
        self::assertSame('configured', $consumer->label);
        self::assertSame([null, 'handled'], $consumer->handlers);
        self::assertSame('contextual', $consumer->dependency->label);
        self::assertSame(
            $container->get('dependency'),
            $consumer->dependency,
        );
    }

    #[DataProvider('factoryModes')]
    public function testSingletonCannotCaptureScopedDependency(
        bool $compiled,
    ): void {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'consumer',
            DefinitionConsumer::class,
            lifetime: DefinitionLifetime::Singleton,
        ));
        $builder->add(ServiceDefinition::forAutowire(
            'dependency',
            DefinitionDependency::class,
            lifetime: DefinitionLifetime::Scoped,
        ));
        $builder->bindContext(
            'consumer',
            DefinitionDependency::class,
            'dependency',
        );

        $container = $this->create($builder->build(), $compiled);

        $container->runInScope(
            static function (ContainerInterface $resolver): void {
                $failure = self::captureResolutionFailure(
                    static fn (): mixed => $resolver->get('consumer'),
                );

                self::assertInstanceOf(
                    LifetimeViolationException::class,
                    $failure->getPrevious(),
                );
                self::assertSame(
                    ['consumer', 'dependency'],
                    $failure->dependencyPath(),
                );

                self::assertInstanceOf(
                    DefinitionDependency::class,
                    $resolver->get('dependency'),
                );
            },
        );
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function factoryModes(): iterable
    {
        yield 'reflective replay' => [false];
        yield 'prepared constructors' => [true];
    }

    public function testConstructorExecutionIsDeferredAndStrict(): void
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'service',
            DefinitionDependency::class,
            arguments: ['label' => 123],
        ));

        $container = new CompiledDefinitionContainerFactory()->create(
            $builder->build(),
        );

        self::assertTrue($container->has('service'));

        $failure = self::captureResolutionFailure(
            static fn (): mixed => $container->get('service'),
        );

        self::assertInstanceOf(TypeError::class, $failure->getPrevious());
        self::assertSame(['service'], $failure->dependencyPath());
    }

    #[DataProvider('factoryModes')]
    public function testLazySingletonIdentityAndInitializationMatch(
        bool $compiled,
    ): void {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'lazy',
            DefinitionDependency::class,
            lifetime: DefinitionLifetime::Singleton,
            arguments: ['label' => 'initialized'],
            lazy: true,
        ));
        $builder->alias('alias', 'lazy');

        $container = $this->create($builder->build(), $compiled);
        $service = $container->get('alias');

        self::assertTrue($container->isFrozen());
        self::assertInstanceOf(DefinitionDependency::class, $service);
        self::assertSame($service, $container->get('lazy'));

        $lazyClass = new LazyClass(DefinitionDependency::class);

        self::assertTrue($lazyClass->isUninitialized($service));
        self::assertSame('initialized', $service->label);
        self::assertFalse($lazyClass->isUninitialized($service));
        self::assertSame($service, $container->get('alias'));
    }

    #[DataProvider('factoryModes')]
    public function testLazyContextTagsAndLiteralsMatch(bool $compiled): void
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'consumer',
            DefinitionConsumer::class,
            lifetime: DefinitionLifetime::Singleton,
            arguments: ['label' => 'configured'],
            taggedArguments: ['handlers' => 'handlers'],
            lazy: true,
        ));
        $builder->add(ServiceDefinition::forAutowire(
            'dependency',
            DefinitionDependency::class,
            lifetime: DefinitionLifetime::Singleton,
            arguments: ['label' => 'contextual'],
        ));
        $builder->add(ServiceDefinition::forValue('first', null));
        $builder->add(ServiceDefinition::forValue('second', 'handled'));
        $builder->alias('consumer.alias', 'consumer');
        $builder->alias('dependency.alias', 'dependency');
        $builder->tag('handlers', 'first', 'second');
        $builder->bindContext(
            'consumer.alias',
            DefinitionDependency::class,
            'dependency.alias',
        );

        $container = $this->create($builder->build(), $compiled);
        $consumer = $container->get('consumer.alias');

        self::assertInstanceOf(DefinitionConsumer::class, $consumer);

        $lazyClass = new LazyClass(DefinitionConsumer::class);

        self::assertTrue($lazyClass->isUninitialized($consumer));
        self::assertSame('configured', $consumer->label);
        self::assertSame([null, 'handled'], $consumer->handlers);
        self::assertSame('contextual', $consumer->dependency->label);
        self::assertSame(
            $container->get('dependency.alias'),
            $consumer->dependency,
        );
        self::assertFalse($lazyClass->isUninitialized($consumer));
    }

    public function testLazyMissingDependencyFailsOnlyDuringInitialization(): void
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'consumer',
            DefinitionConsumer::class,
            lifetime: DefinitionLifetime::Singleton,
            lazy: true,
        ));

        $container = new CompiledDefinitionContainerFactory()->create(
            $builder->build(),
        );
        $consumer = $container->get('consumer');

        self::assertInstanceOf(DefinitionConsumer::class, $consumer);

        $lazyClass = new LazyClass(DefinitionConsumer::class);

        self::assertTrue($lazyClass->isUninitialized($consumer));

        $failure = self::captureResolutionFailure(
            static fn (): DefinitionDependency => $consumer->dependency,
        );

        self::assertInstanceOf(
            EntryNotFoundException::class,
            $failure->getPrevious(),
        );
        self::assertSame(
            ['consumer', DefinitionDependency::class],
            $failure->dependencyPath(),
        );
        self::assertTrue($lazyClass->isUninitialized($consumer));
        self::assertSame($consumer, $container->get('consumer'));

        self::assertSame(
            'clean',
            $container->runInScope(
                static fn (ContainerInterface $resolver): string => 'clean',
            ),
        );
    }

    public function testLazyConstructorInvocationRemainsStrict(): void
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'lazy',
            DefinitionDependency::class,
            lifetime: DefinitionLifetime::Singleton,
            arguments: ['label' => 123],
            lazy: true,
        ));

        $container = new CompiledDefinitionContainerFactory()->create(
            $builder->build(),
        );
        $service = $container->get('lazy');

        self::assertInstanceOf(DefinitionDependency::class, $service);

        $lazyClass = new LazyClass(DefinitionDependency::class);

        self::assertTrue($lazyClass->isUninitialized($service));

        $failure = self::captureResolutionFailure(
            static fn (): string => $service->label,
        );

        self::assertInstanceOf(TypeError::class, $failure->getPrevious());
        self::assertSame(['lazy'], $failure->dependencyPath());
        self::assertTrue($lazyClass->isUninitialized($service));
        self::assertSame($service, $container->get('lazy'));
    }

    #[DataProvider('factoryModes')]
    public function testLazySingletonCannotCaptureScopedDependency(
        bool $compiled,
    ): void {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'consumer',
            DefinitionConsumer::class,
            lifetime: DefinitionLifetime::Singleton,
            lazy: true,
        ));
        $builder->add(ServiceDefinition::forAutowire(
            'dependency',
            DefinitionDependency::class,
            lifetime: DefinitionLifetime::Scoped,
        ));
        $builder->bindContext(
            'consumer',
            DefinitionDependency::class,
            'dependency',
        );

        $container = $this->create($builder->build(), $compiled);
        $consumer = $container->get('consumer');

        self::assertInstanceOf(DefinitionConsumer::class, $consumer);

        $container->runInScope(
            static function (ContainerInterface $resolver) use (
                $consumer,
            ): null {
                $failure = self::captureResolutionFailure(
                    static fn (): DefinitionDependency => $consumer->dependency,
                );

                self::assertInstanceOf(
                    LifetimeViolationException::class,
                    $failure->getPrevious(),
                );
                self::assertSame(
                    ['consumer', 'dependency'],
                    $failure->dependencyPath(),
                );
                self::assertInstanceOf(
                    DefinitionDependency::class,
                    $resolver->get('dependency'),
                );

                return null;
            },
        );

        self::assertTrue(
            (new LazyClass(DefinitionConsumer::class))->isUninitialized(
                $consumer,
            ),
        );

        self::assertSame(
            'clean',
            $container->runInScope(
                static fn (ContainerInterface $resolver): string => 'clean',
            ),
        );
    }

    public function testSeparateContainersHaveSeparateLazySingletons(): void
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'lazy',
            DefinitionDependency::class,
            lifetime: DefinitionLifetime::Singleton,
            arguments: ['label' => 'independent'],
            lazy: true,
        ));

        $definitions = $builder->build();
        $factory = new CompiledDefinitionContainerFactory();

        $firstContainer = $factory->create($definitions);
        $secondContainer = $factory->create($definitions);

        $first = $firstContainer->get('lazy');
        $second = $secondContainer->get('lazy');

        self::assertInstanceOf(DefinitionDependency::class, $first);
        self::assertInstanceOf(DefinitionDependency::class, $second);
        self::assertNotSame($first, $second);

        $lazyClass = new LazyClass(DefinitionDependency::class);

        self::assertTrue($lazyClass->isUninitialized($first));
        self::assertTrue($lazyClass->isUninitialized($second));

        self::assertSame('independent', $first->label);
        self::assertFalse($lazyClass->isUninitialized($first));
        self::assertTrue($lazyClass->isUninitialized($second));

        self::assertSame('independent', $second->label);
        self::assertFalse($lazyClass->isUninitialized($second));
    }

    public function testIneligibleLazyClassFailsDuringCreation(): void
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'lazy',
            stdClass::class,
            lifetime: DefinitionLifetime::Singleton,
            lazy: true,
        ));

        $failure = self::captureResolutionFailure(
            static fn (): Container =>
                new CompiledDefinitionContainerFactory()->create(
                    $builder->build(),
                ),
        );

        self::assertSame(
            'The prepared lazy entry could not be registered.',
            $failure->getMessage(),
        );
        self::assertInstanceOf(
            InvalidArgumentException::class,
            $failure->getPrevious(),
        );
    }

    public function testInvalidConstructorPreservesItsDiscoveryCause(): void
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'service',
            DefinitionDependency::class,
            arguments: ['unknownParameter' => null],
        ));

        try {
            new CompiledDefinitionContainerFactory()->create($builder->build());
        } catch (DefinitionException $exception) {
            self::assertInstanceOf(
                InvalidArgumentException::class,
                $exception->getPrevious(),
            );
            self::assertSame(
                'A compiled constructor could not be prepared.',
                $exception->getMessage(),
            );

            return;
        }

        self::fail('Invalid constructor configuration must be rejected.');
    }

    public function testUnmatchedContextFailsBeforeReturningAContainer(): void
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'consumer',
            DefinitionConsumer::class,
        ));
        $builder->add(ServiceDefinition::forValue('target', null));
        $builder->bindContext('consumer', 'UnmatchedDependency', 'target');

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage(
            'A compiled contextual dependency does not match the constructor.',
        );

        new CompiledDefinitionContainerFactory()->create($builder->build());
    }

    public function testSeparateContainersHaveSeparateSingletons(): void
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'service',
            DefinitionDependency::class,
            lifetime: DefinitionLifetime::Singleton,
        ));

        $definitions = $builder->build();
        $factory = new CompiledDefinitionContainerFactory();

        $first = $factory->create($definitions);
        $second = $factory->create($definitions);

        self::assertNotSame(
            $first->get('service'),
            $second->get('service'),
        );
    }

    public function testDirectSnapshotIsRevalidated(): void
    {
        $definitions = new DefinitionSet(
            services: [],
            aliases: [['alias' => 'alias', 'target' => 'missing']],
            tags: [],
            contexts: [],
        );

        $this->expectException(DefinitionException::class);

        new CompiledDefinitionContainerFactory()->create($definitions);
    }

    private function create(
        DefinitionSet $definitions,
        bool $compiled,
    ): Container {
        if ($compiled) {
            return new CompiledDefinitionContainerFactory()->create(
                $definitions,
            );
        }

        return new DefinitionContainerFactory()->create($definitions);
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

        self::fail('The operation must fail during resolution.');
    }
}
