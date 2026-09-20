<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Container\Compilation\DefinitionBuilder;
use Careminate\Container\Compilation\ServiceDefinition;
use Careminate\Container\Exception\ResolutionException;
use Careminate\Module\Exception\InvalidModuleBoundaryException;
use Careminate\Module\Internal\ModuleBoundaryContainerFactory;
use Careminate\Module\Internal\ModuleServiceDefinitions;
use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleIdentifier;
use CareminateIntegration\Tests\Fixtures\Module\BoundaryConsumer;
use CareminateIntegration\Tests\Fixtures\Module\BoundaryDependency;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ModuleScopedContainerTest extends TestCase
{
    public function testOwnerCanReadPrivateServiceWithoutExposingItToApplication(): void
    {
        $containers = new ModuleBoundaryContainerFactory()->compose(
            self::snapshot(),
        );

        $base = $containers->forModule(new ModuleIdentifier('base'));

        self::assertTrue($base->has('base.private'));
        self::assertSame('private-value', $base->get('base.private'));

        $this->expectException(ResolutionException::class);
        $this->expectExceptionMessage(
            'Access to the requested service is denied.',
        );

        $containers->application->get('base.private');
    }

    public function testDependencyViewExposesOnlyExportedServices(): void
    {
        $containers = new ModuleBoundaryContainerFactory()->compose(
            self::snapshot(),
        );

        $consumer = $containers->forModule(
            new ModuleIdentifier('consumer-module'),
        );

        self::assertTrue($consumer->has('base.public'));
        self::assertSame('public-value', $consumer->get('base.public'));
        self::assertFalse($consumer->has('base.private'));
        self::assertFalse($consumer->has('missing'));

        $this->expectException(ResolutionException::class);

        $consumer->get('base.private');
    }

    public function testNestedConstructionUsesServiceOwnerInsteadOfLifecycleOwner(): void
    {
        $containers = new ModuleBoundaryContainerFactory()->compose(
            self::snapshot(),
        );

        $consumer = $containers->forModule(
            new ModuleIdentifier('consumer-module'),
        );

        try {
            $consumer->get('consumer.private');
        } catch (ResolutionException $exception) {
            self::assertSame(
                ['consumer.private', BoundaryDependency::class],
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

        self::fail('Nested construction must respect service ownership.');
    }

    public function testFailedLookupDoesNotLeaveLifecycleContextActive(): void
    {
        $containers = new ModuleBoundaryContainerFactory()->compose(
            self::snapshot(),
        );

        $base = $containers->forModule(new ModuleIdentifier('base'));

        try {
            $base->get('missing');
            self::fail('The missing service must be rejected.');
        } catch (ResolutionException $exception) {
            self::assertSame(
                'Access to the requested service is denied.',
                $exception->getMessage(),
            );
        }

        self::assertSame('private-value', $base->get('base.private'));

        $this->expectException(ResolutionException::class);

        $containers->application->get('base.private');
    }

    public function testSeparateViewsRetainTheirOwnModuleIdentity(): void
    {
        $containers = new ModuleBoundaryContainerFactory()->compose(
            self::snapshot(),
        );

        $base = $containers->forModule(new ModuleIdentifier('base'));
        $stranger = $containers->forModule(new ModuleIdentifier('stranger'));

        self::assertTrue($base->has('base.private'));
        self::assertFalse($stranger->has('base.private'));
        self::assertFalse($stranger->has('base.public'));

        self::assertSame('private-value', $base->get('base.private'));

        $this->expectException(ResolutionException::class);

        $stranger->get('base.public');
    }

    public function testUnknownModuleCannotAcquireAView(): void
    {
        $containers = new ModuleBoundaryContainerFactory()->compose(
            self::snapshot(),
        );

        $this->expectException(InvalidModuleBoundaryException::class);

        $containers->forModule(new ModuleIdentifier('unknown'));
    }

    public function testViewsShareExplicitRuntimeObjects(): void
    {
        $runtime = new stdClass();

        $containers = new ModuleBoundaryContainerFactory()->compose(
            self::snapshot(),
            ['runtime.object' => $runtime],
        );

        $base = $containers->forModule(new ModuleIdentifier('base'));
        $stranger = $containers->forModule(new ModuleIdentifier('stranger'));

        self::assertTrue($base->has('runtime.object'));
        self::assertSame($runtime, $base->get('runtime.object'));
        self::assertSame($runtime, $stranger->get('runtime.object'));
        self::assertSame(
            $runtime,
            $containers->application->get('runtime.object'),
        );
    }

    private static function snapshot(): ModuleServiceDefinitions
    {
        $base = new ModuleIdentifier('base');
        $consumer = new ModuleIdentifier('consumer-module');

        $builder = new DefinitionBuilder();
        $builder->add(
            ServiceDefinition::forValue('base.private', 'private-value'),
        );
        $builder->add(
            ServiceDefinition::forValue('base.public', 'public-value'),
        );
        $builder->add(
            ServiceDefinition::forAutowire(
                BoundaryDependency::class,
                BoundaryDependency::class,
            ),
        );
        $builder->add(
            ServiceDefinition::forAutowire(
                'consumer.private',
                BoundaryConsumer::class,
            ),
        );

        return new ModuleServiceDefinitions(
            $builder->build(),
            [
                new ModuleDefinition($base),
                new ModuleDefinition($consumer, required: [$base]),
                new ModuleDefinition(new ModuleIdentifier('stranger')),
            ],
            [
                'entry:base.private' => $base,
                'entry:base.public' => $base,
                'entry:' . BoundaryDependency::class => $base,
                'entry:consumer.private' => $consumer,
            ],
            ['base.public'],
        );
    }
}
