<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container\Compilation;

use Careminate\Container\Compilation\DefinitionBuilder;
use Careminate\Container\Compilation\DefinitionContainerFactory;
use Careminate\Container\Compilation\DefinitionLifetime;
use Careminate\Container\Compilation\DefinitionSet;
use Careminate\Container\Compilation\Exception\DefinitionException;
use Careminate\Container\Compilation\ServiceDefinition;
use Careminate\Container\Container;
use Careminate\Container\Exception\ContextBindingException;
use Careminate\Container\Exception\FrozenContainerException;
use Careminate\Container\Exception\LifetimeViolationException;
use Careminate\Container\Exception\ResolutionException;
use CareminateIntegration\Tests\Fixtures\Container\DefinitionConsumer;
use CareminateIntegration\Tests\Fixtures\Container\DefinitionDependency;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use TypeError;

final class DefinitionContainerFactoryTest extends TestCase
{
    public function testResultIsFrozenByDefault(): void
    {
        $container = new DefinitionContainerFactory()->create(
            new DefinitionBuilder()->build(),
        );

        self::assertTrue($container->isFrozen());

        $this->expectException(FrozenContainerException::class);

        $container->register('late', null);
    }

    public function testMutableResultMustBeRequestedExplicitly(): void
    {
        $container = new DefinitionContainerFactory()->create(
            new DefinitionBuilder()->build(),
            freeze: false,
        );

        self::assertFalse($container->isFrozen());

        $container->register('late', null);

        self::assertTrue($container->has('late'));
        self::assertNull($container->get('late'));
    }

    #[DataProvider('registrationModes')]
    public function testTransientAndSingletonBehaviorMatchesRuntimeRegistration(
        bool $useDefinitions,
    ): void {
        $container = $this->makeContainer($useDefinitions);

        $first = $container->get('transient');
        $second = $container->get('transient');

        self::assertInstanceOf(DefinitionDependency::class, $first);
        self::assertInstanceOf(DefinitionDependency::class, $second);
        self::assertNotSame($first, $second);

        $singleton = $container->get('singleton');

        self::assertInstanceOf(DefinitionDependency::class, $singleton);
        self::assertSame($singleton, $container->get('singleton'));
        self::assertSame($singleton, $container->get('alias'));

        self::assertSame(
            ['first', 'second', 'first'],
            $container->tagged('handlers'),
        );
    }

    #[DataProvider('registrationModes')]
    public function testScopedIdentityMatchesRuntimeRegistration(
        bool $useDefinitions,
    ): void {
        $container = $this->makeContainer($useDefinitions);

        $first = $container->runInScope(
            static function (ContainerInterface $resolver): mixed {
                $service = $resolver->get('scoped');

                self::assertSame($service, $resolver->get('scoped'));

                return $service;
            },
        );

        $second = $container->runInScope(
            static function (ContainerInterface $resolver): mixed {
                $service = $resolver->get('scoped');

                self::assertSame($service, $resolver->get('scoped'));

                return $service;
            },
        );

        self::assertInstanceOf(DefinitionDependency::class, $first);
        self::assertInstanceOf(DefinitionDependency::class, $second);
        self::assertNotSame($first, $second);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function registrationModes(): iterable
    {
        yield 'runtime registrations' => [false];
        yield 'definition registrations' => [true];
    }

    public function testContextTagsAndLiteralArgumentsAreApplied(): void
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

        $builder->add(ServiceDefinition::forValue('handler', 'handled'));
        $builder->alias('dependency.alias', 'dependency');
        $builder->tag('handlers', 'handler');
        $builder->bindContext(
            'consumer',
            DefinitionDependency::class,
            'dependency.alias',
        );

        $container = new DefinitionContainerFactory()->create($builder->build());
        $consumer = $container->get('consumer');

        self::assertInstanceOf(DefinitionConsumer::class, $consumer);
        self::assertSame('configured', $consumer->label);
        self::assertSame(['handled'], $consumer->handlers);
        self::assertSame('contextual', $consumer->dependency->label);
        self::assertSame(
            $container->get('dependency'),
            $consumer->dependency,
        );
    }

    public function testLazyDefinitionRetainsUninitializedSingletonIdentity(): void
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'lazy',
            DefinitionDependency::class,
            lifetime: DefinitionLifetime::Singleton,
            arguments: ['label' => 'initialized'],
            lazy: true,
        ));

        $container = new DefinitionContainerFactory()->create($builder->build());
        $service = $container->get('lazy');

        self::assertInstanceOf(DefinitionDependency::class, $service);

        $reflection = new ReflectionClass($service);

        self::assertTrue($reflection->isUninitializedLazyObject($service));
        self::assertSame($service, $container->get('lazy'));
        self::assertSame('initialized', $service->label);
        self::assertFalse($reflection->isUninitializedLazyObject($service));
    }

    public function testConstructionIsDeferredUntilResolution(): void
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'invalid.argument',
            DefinitionDependency::class,
            arguments: ['label' => []],
        ));

        $container = new DefinitionContainerFactory()->create($builder->build());

        self::assertTrue($container->has('invalid.argument'));

        $failure = self::captureResolutionFailure(
            static fn (): mixed => $container->get('invalid.argument'),
        );

        self::assertInstanceOf(TypeError::class, $failure->getPrevious());
        self::assertSame(
            ['invalid.argument'],
            $failure->dependencyPath(),
        );
    }

    public function testUnmatchedContextualDependencyFailsDuringCreation(): void
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'consumer',
            DefinitionConsumer::class,
        ));

        $builder->add(ServiceDefinition::forValue('target', null));
        $builder->bindContext('consumer', 'UnmatchedDependency', 'target');

        $this->expectException(ContextBindingException::class);

        new DefinitionContainerFactory()->create($builder->build());
    }

    public function testUnknownConstructorArgumentFailsDuringCreation(): void
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'service',
            DefinitionDependency::class,
            arguments: ['unknownParameter' => 'value'],
        ));

        $this->expectException(ResolutionException::class);

        new DefinitionContainerFactory()->create($builder->build());
    }

    public function testSingletonCannotCaptureScopedDependency(): void
    {
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

        $container = new DefinitionContainerFactory()->create($builder->build());

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

    public function testSeparateContainersDoNotShareSingletonInstances(): void
    {
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forAutowire(
            'singleton',
            DefinitionDependency::class,
            lifetime: DefinitionLifetime::Singleton,
        ));

        $definitions = $builder->build();
        $factory = new DefinitionContainerFactory();

        $first = $factory->create($definitions);
        $second = $factory->create($definitions);

        self::assertNotSame(
            $first->get('singleton'),
            $second->get('singleton'),
        );
    }

    public function testDirectSnapshotCannotBypassRelationshipValidation(): void
    {
        $definitions = new DefinitionSet(
            services: [],
            aliases: [['alias' => 'alias', 'target' => 'missing']],
            tags: [],
            contexts: [],
        );

        $this->expectException(DefinitionException::class);

        new DefinitionContainerFactory()->create($definitions);
    }

    private function makeContainer(bool $useDefinitions): Container
    {
        if (!$useDefinitions) {
            $container = new Container();

            $container->autowire('transient', DefinitionDependency::class);
            $container->autowire(
                'singleton',
                DefinitionDependency::class,
                shared: true,
            );
            $container->scopedAutowire('scoped', DefinitionDependency::class);
            $container->register('first', 'first');
            $container->register('second', 'second');
            $container->alias('alias', 'singleton');
            $container->alias('first.alias', 'first');
            $container->tag('handlers', 'first', 'second', 'first.alias');
            $container->freeze();

            return $container;
        }

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
        $builder->add(ServiceDefinition::forAutowire(
            'scoped',
            DefinitionDependency::class,
            lifetime: DefinitionLifetime::Scoped,
        ));
        $builder->add(ServiceDefinition::forValue('first', 'first'));
        $builder->add(ServiceDefinition::forValue('second', 'second'));
        $builder->alias('alias', 'singleton');
        $builder->alias('first.alias', 'first');
        $builder->tag('handlers', 'first', 'second', 'first.alias');

        return new DefinitionContainerFactory()->create($builder->build());
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

        self::fail('Resolution must fail.');
    }
}
