<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Container\Compilation\CompiledDefinitionContainerFactory;
use Careminate\Container\Compilation\DefinitionBuilder;
use Careminate\Container\Compilation\DefinitionLifetime;
use Careminate\Container\Compilation\ServiceDefinition;
use Careminate\Container\Exception\ResolutionException;
use Careminate\Module\Internal\ModuleArtifactCodec;
use Careminate\Module\Internal\ModuleBoundaryContainerFactory;
use Careminate\Module\Internal\ModuleCacheIdentity;
use Careminate\Module\Internal\ModuleServiceDefinitions;
use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleIdentifier;
use CareminateIntegration\Tests\Fixtures\Module\BoundaryConsumer;
use CareminateIntegration\Tests\Fixtures\Module\BoundaryDependency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ModuleBoundaryContainerFactoryTest extends TestCase
{
    #[DataProvider('constructionModes')]
    public function testSameOwnerCanResolvePrivateDependency(
        bool $lazy,
        bool $cached,
    ): void {
        $snapshot = self::snapshot($lazy, crossModule: false);
        $snapshot = self::prepareSnapshot($snapshot, $cached);

        $container = new ModuleBoundaryContainerFactory()->create($snapshot);

        self::assertTrue($container->isFrozen());

        $consumer = $container->get('consumer');

        self::assertInstanceOf(BoundaryConsumer::class, $consumer);
        self::assertSame('ready', $consumer->dependency->value);
        self::assertSame($consumer, $container->get('consumer'));

        // The private dependency has already been constructed and cached.
        // Application access must still be denied.
        $this->expectException(ResolutionException::class);
        $this->expectExceptionMessage(
            'Access to the requested service is denied.',
        );

        $container->get(BoundaryDependency::class);
    }

    #[DataProvider('constructionModes')]
    public function testCrossModulePrivateDependencyIsDenied(
        bool $lazy,
        bool $cached,
    ): void {
        $snapshot = self::snapshot($lazy, crossModule: true);
        $snapshot = self::prepareSnapshot($snapshot, $cached);

        $container = new ModuleBoundaryContainerFactory()->create($snapshot);

        try {
            $consumer = $container->get('consumer');

            self::assertInstanceOf(BoundaryConsumer::class, $consumer);

            // Reading the property triggers lazy constructor execution.
            $dependency = $consumer->dependency;
        } catch (ResolutionException $exception) {
            self::assertSame(
                ['consumer', BoundaryDependency::class],
                $exception->dependencyPath(),
            );

            $previous = $exception->getPrevious();

            self::assertInstanceOf(ResolutionException::class, $previous);
            self::assertSame(
                'Access to the requested service is denied.',
                $previous->getMessage(),
            );

            return;
        }

        self::fail('A cross-module private dependency must be denied.');
    }

    #[DataProvider('constructionModes')]
    public function testExportedDirectDependencyIsAllowed(
        bool $lazy,
        bool $cached,
    ): void {
        $snapshot = self::snapshot(
            $lazy,
            crossModule: true,
            exportDependency: true,
        );

        $snapshot = self::prepareSnapshot($snapshot, $cached);

        $container = new ModuleBoundaryContainerFactory()->create($snapshot);
        $consumer = $container->get('consumer');

        self::assertInstanceOf(BoundaryConsumer::class, $consumer);
        self::assertSame('ready', $consumer->dependency->value);

        self::assertSame(
            $consumer->dependency,
            $container->get(BoundaryDependency::class),
        );
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function constructionModes(): iterable
    {
        yield 'eager direct' => [false, false];
        yield 'lazy direct' => [true, false];
        yield 'eager artifact' => [false, true];
        yield 'lazy artifact' => [true, true];
    }

    public function testRuntimeObjectsAreExplicitlyShared(): void
    {
        $snapshot = new ModuleServiceDefinitions(
            new DefinitionBuilder()->build(),
            [],
            [],
        );

        $runtimeObject = new stdClass();

        $container = new ModuleBoundaryContainerFactory()->create(
            $snapshot,
            ['runtime.object' => $runtimeObject],
        );

        self::assertSame(
            $runtimeObject,
            $container->get('runtime.object'),
        );
        self::assertTrue($container->isFrozen());
    }

    public function testExistingCompiledFactoryRemainsUnrestrictedByDefault(): void
    {
        $snapshot = self::snapshot(
            lazy: false,
            crossModule: true,
        );

        $container = new CompiledDefinitionContainerFactory()->create(
            $snapshot->definitions,
        );

        $consumer = $container->get('consumer');

        self::assertInstanceOf(BoundaryConsumer::class, $consumer);
        self::assertSame(
            $consumer->dependency,
            $container->get(BoundaryDependency::class),
        );
    }

    private static function snapshot(
        bool $lazy,
        bool $crossModule,
        bool $exportDependency = false,
    ): ModuleServiceDefinitions {
        $base = new ModuleIdentifier('base');
        $consumerOwner = $crossModule
            ? new ModuleIdentifier('consumer-module')
            : $base;

        $modules = [new ModuleDefinition($base)];

        if ($crossModule) {
            $modules[] = new ModuleDefinition(
                $consumerOwner,
                required: [$base],
            );
        }

        $builder = new DefinitionBuilder();

        $builder->add(
            ServiceDefinition::forAutowire(
                BoundaryDependency::class,
                BoundaryDependency::class,
                DefinitionLifetime::Singleton,
            ),
        );

        $builder->add(
            ServiceDefinition::forAutowire(
                'consumer',
                BoundaryConsumer::class,
                DefinitionLifetime::Singleton,
                lazy: $lazy,
            ),
        );

        $exports = ['consumer'];

        if ($exportDependency) {
            $exports[] = BoundaryDependency::class;
        }

        return new ModuleServiceDefinitions(
            $builder->build(),
            $modules,
            [
                'entry:' . BoundaryDependency::class => $base,
                'entry:consumer' => $consumerOwner,
            ],
            $exports,
        );
    }

    private static function prepareSnapshot(
        ModuleServiceDefinitions $snapshot,
        bool $cached,
    ): ModuleServiceDefinitions {
        if (!$cached) {
            return $snapshot;
        }

        $codec = new ModuleArtifactCodec();
        $identity = new ModuleCacheIdentity('boundary-container-test', []);
        $artifact = $codec->encode($snapshot, $identity);

        return $codec->decode(
            $artifact,
            $identity,
            $codec->fingerprint($artifact),
        );
    }
}
