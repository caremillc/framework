<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Container\Compilation;

use Careminate\Container\Compilation\CompiledDefinitionContainerFactory;
use Careminate\Container\Compilation\DefinitionBuilder;
use Careminate\Container\Compilation\DefinitionLifetime;
use Careminate\Container\Compilation\ServiceDefinition;
use Careminate\Container\Exception\EntryNotFoundException;
use Careminate\Container\Exception\ResolutionException;
use Careminate\Container\Internal\LazyClass;
use CareminateIntegration\Tests\Fixtures\Container\DefinitionDependency;
use CareminateIntegration\Tests\Fixtures\Container\PreparedLazyArgumentService;
use Closure;
use PHPUnit\Framework\TestCase;

final class CompiledLazyArgumentsTest extends TestCase
{
    public function testAttributesResolveDependencyAndOrderedTagMembers(): void
    {
        $builder = new DefinitionBuilder();

        self::addLazyService($builder, 'service');
        self::addDependency($builder, 'attribute.dependency', 'attribute');

        $builder->add(ServiceDefinition::forValue('first', null));
        $builder->add(ServiceDefinition::forValue('second', 'handled'));
        $builder->alias('second.alias', 'second');
        $builder->tag(
            'attribute.handlers',
            'first',
            'second',
            'second.alias',
        );

        $container = new CompiledDefinitionContainerFactory()->create(
            $builder->build(),
        );

        $service = $container->get('service');

        self::assertInstanceOf(PreparedLazyArgumentService::class, $service);

        $lazyClass = new LazyClass(PreparedLazyArgumentService::class);

        self::assertTrue($lazyClass->isUninitialized($service));

        self::assertSame('attribute', $service->dependency->label);
        self::assertSame(
            $container->get('attribute.dependency'),
            $service->dependency,
        );
        self::assertSame(
            [null, 'handled', 'handled'],
            $service->handlers,
        );
        self::assertSame('default', $service->label);
        self::assertFalse($lazyClass->isUninitialized($service));
    }

    public function testOmittedObjectDefaultsBelongToEachService(): void
    {
        $builder = new DefinitionBuilder();

        self::addLazyService($builder, 'first');
        self::addLazyService($builder, 'second');
        self::addDependency($builder, 'attribute.dependency', 'shared');

        $container = new CompiledDefinitionContainerFactory()->create(
            $builder->build(),
        );

        $first = $container->get('first');
        $second = $container->get('second');

        self::assertInstanceOf(PreparedLazyArgumentService::class, $first);
        self::assertInstanceOf(PreparedLazyArgumentService::class, $second);
        self::assertNotSame($first, $second);

        $lazyClass = new LazyClass(PreparedLazyArgumentService::class);

        self::assertTrue($lazyClass->isUninitialized($first));
        self::assertTrue($lazyClass->isUninitialized($second));

        $firstToken = $first->token;

        self::assertFalse($lazyClass->isUninitialized($first));
        self::assertTrue($lazyClass->isUninitialized($second));

        $secondToken = $second->token;

        self::assertNotSame($firstToken, $secondToken);
        self::assertSame($firstToken, $first->token);
        self::assertSame($secondToken, $second->token);
        self::assertSame($first->dependency, $second->dependency);
    }

    public function testObjectDefaultIsNotSharedAcrossFactoryCreations(): void
    {
        $builder = new DefinitionBuilder();

        self::addLazyService($builder, 'service');
        self::addDependency($builder, 'attribute.dependency', 'dependency');

        $definitions = $builder->build();
        $factory = new CompiledDefinitionContainerFactory();

        $firstContainer = $factory->create($definitions);
        $secondContainer = $factory->create($definitions);

        $first = $firstContainer->get('service');
        $second = $secondContainer->get('service');

        self::assertInstanceOf(PreparedLazyArgumentService::class, $first);
        self::assertInstanceOf(PreparedLazyArgumentService::class, $second);

        self::assertNotSame($first->token, $second->token);
        self::assertNotSame($first->dependency, $second->dependency);
        self::assertSame($first, $firstContainer->get('service'));
        self::assertSame($second, $secondContainer->get('service'));
    }

    public function testExplicitNullAndContextOverrideArePreserved(): void
    {
        $builder = new DefinitionBuilder();

        self::addLazyService($builder, 'service', ['label' => null]);
        self::addDependency($builder, 'context.dependency', 'contextual');

        $builder->alias('service.alias', 'service');
        $builder->alias('context.alias', 'context.dependency');
        $builder->bindContext(
            'service.alias',
            DefinitionDependency::class,
            'context.alias',
        );

        $container = new CompiledDefinitionContainerFactory()->create(
            $builder->build(),
        );

        self::assertFalse($container->has('attribute.dependency'));

        $service = $container->get('service.alias');

        self::assertInstanceOf(PreparedLazyArgumentService::class, $service);

        self::assertNull($service->label);
        self::assertSame('contextual', $service->dependency->label);
        self::assertSame(
            $container->get('context.dependency'),
            $service->dependency,
        );
        self::assertSame([], $service->handlers);
    }

    public function testExplicitTaggedArgumentOverridesAttributeTag(): void
    {
        $builder = new DefinitionBuilder();

        self::addLazyService(
            $builder,
            'service',
            taggedArguments: ['handlers' => 'configured.handlers'],
        );
        self::addDependency($builder, 'attribute.dependency', 'dependency');

        $builder->add(ServiceDefinition::forValue('attribute.handler', 'unused'));
        $builder->add(ServiceDefinition::forValue('configured.handler', 'selected'));

        $builder->tag('attribute.handlers', 'attribute.handler');
        $builder->tag('configured.handlers', 'configured.handler');

        $container = new CompiledDefinitionContainerFactory()->create(
            $builder->build(),
        );

        $service = $container->get('service');

        self::assertInstanceOf(PreparedLazyArgumentService::class, $service);
        self::assertSame(['selected'], $service->handlers);
    }

    public function testMissingAttributeDependencyFailsDuringInitialization(): void
    {
        $builder = new DefinitionBuilder();

        self::addLazyService($builder, 'service');

        $container = new CompiledDefinitionContainerFactory()->create(
            $builder->build(),
        );

        $service = $container->get('service');

        self::assertInstanceOf(PreparedLazyArgumentService::class, $service);

        $lazyClass = new LazyClass(PreparedLazyArgumentService::class);

        self::assertTrue($lazyClass->isUninitialized($service));

        $failure = self::captureFailure(
            static fn (): DefinitionDependency => $service->dependency,
        );

        self::assertInstanceOf(
            EntryNotFoundException::class,
            $failure->getPrevious(),
        );
        self::assertSame(
            ['service', 'attribute.dependency'],
            $failure->dependencyPath(),
        );

        self::assertTrue($lazyClass->isUninitialized($service));
        self::assertSame($service, $container->get('service'));
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, string> $taggedArguments
     */
    private static function addLazyService(
        DefinitionBuilder $builder,
        string $id,
        array $arguments = [],
        array $taggedArguments = [],
    ): void {
        $builder->add(ServiceDefinition::forAutowire(
            $id,
            PreparedLazyArgumentService::class,
            lifetime: DefinitionLifetime::Singleton,
            arguments: $arguments,
            taggedArguments: $taggedArguments,
            lazy: true,
        ));
    }

    private static function addDependency(
        DefinitionBuilder $builder,
        string $id,
        string $label,
    ): void {
        $builder->add(ServiceDefinition::forAutowire(
            $id,
            DefinitionDependency::class,
            lifetime: DefinitionLifetime::Singleton,
            arguments: ['label' => $label],
        ));
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

        self::fail('Lazy initialization must throw a resolution exception.');
    }
}
