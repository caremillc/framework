<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Container\Compilation\DefinitionBuilder;
use Careminate\Container\Compilation\ServiceDefinition;
use Careminate\Module\Exception\InvalidModuleBoundaryException;
use Careminate\Module\Internal\OwnedServiceDefinitions;
use Careminate\Module\Internal\OwnedServiceRegistry;
use Careminate\Module\ModuleIdentifier;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class OwnedServiceExportsTest extends TestCase
{
    public function testServicesAreNotExportedImplicitly(): void
    {
        $registry = self::registry();
        $registry->value('private.value', null);

        $snapshot = $registry->seal();

        self::assertSame([], $snapshot->exportedServices);
        self::assertCount(1, $snapshot->definitions->services);
    }

    public function testValuesAndAutowireDefinitionsCanBeExported(): void
    {
        $registry = self::registry();

        $registry->value('value', null);
        $registry->autowire('object', stdClass::class);

        $registry->export('value');
        $registry->export('object');

        $snapshot = $registry->seal();

        self::assertSame(
            ['object', 'value'],
            $snapshot->exportedServices,
        );
        self::assertCount(2, $snapshot->definitions->services);
    }

    public function testRepeatedExportsAreIdempotent(): void
    {
        $registry = self::registry();
        $registry->value('service', 'ready');

        $registry->export('service');
        $registry->export('service');

        self::assertSame(
            ['service'],
            $registry->seal()->exportedServices,
        );
    }

    public function testServiceMustBeRegisteredBeforeExport(): void
    {
        $registry = self::registry();

        $this->expectException(InvalidModuleBoundaryException::class);

        $registry->export('missing');
    }

    public function testAnotherModulesServiceCannotBeExported(): void
    {
        $first = self::registry();
        $first->value('first.service', 'ready');

        $second = new OwnedServiceRegistry(
            new ModuleIdentifier('second'),
        );

        $this->expectException(InvalidModuleBoundaryException::class);

        $second->export('first.service');
    }

    public function testRejectedExportDoesNotPoisonTheRegistry(): void
    {
        $registry = self::registry();

        try {
            $registry->export('service');
            self::fail('An unregistered service must not be exported.');
        } catch (InvalidModuleBoundaryException $exception) {
            self::assertSame(
                'An exported service must already be registered by this module.',
                $exception->getMessage(),
            );
        }

        $registry->value('service', 'ready');
        $registry->export('service');

        self::assertSame(
            ['service'],
            $registry->seal()->exportedServices,
        );
    }

    public function testSealingReturnsTheSameImmutableSnapshot(): void
    {
        $registry = self::registry();
        $registry->value('service', 'ready');
        $registry->export('service');

        $snapshot = $registry->seal();

        self::assertSame($snapshot, $registry->seal());
        self::assertSame(['service'], $snapshot->exportedServices);
    }

    #[DataProvider('sealedOperations')]
    public function testSealingPreventsEveryRegistrationOperation(
        string $operation,
    ): void {
        $registry = self::registry();
        $registry->value('service', null);
        $registry->seal();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'The module service registry is sealed.',
        );

        match ($operation) {
            'value' => $registry->value('another', null),
            'autowire' => $registry->autowire('another', stdClass::class),
            'export' => $registry->export('service'),
            default => throw new LogicException('Unknown test operation.'),
        };
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sealedOperations(): iterable
    {
        yield 'value' => ['value'];
        yield 'autowire' => ['autowire'];
        yield 'export' => ['export'];
    }

    public function testDirectSnapshotRejectsAnUnknownExport(): void
    {
        $this->expectException(InvalidModuleBoundaryException::class);

        new OwnedServiceDefinitions(
            new ModuleIdentifier('owner'),
            new DefinitionBuilder()->build(),
            ['missing'],
        );
    }

    public function testDirectSnapshotCanonicalizesExports(): void
    {
        $builder = new DefinitionBuilder();
        $builder->add(ServiceDefinition::forValue('zeta', null));
        $builder->add(ServiceDefinition::forValue('alpha', null));

        $snapshot = new OwnedServiceDefinitions(
            new ModuleIdentifier('owner'),
            $builder->build(),
            ['zeta', 'alpha', 'zeta'],
        );

        self::assertSame(
            ['alpha', 'zeta'],
            $snapshot->exportedServices,
        );
    }

    public function testPrefixLikeIdentifiersRemainDistinct(): void
    {
        $registry = self::registry();

        $registry->value('service', null);
        $registry->value('entry:service', null);
        $registry->export('entry:service');

        self::assertSame(
            ['entry:service'],
            $registry->seal()->exportedServices,
        );
    }

    private static function registry(): OwnedServiceRegistry
    {
        return new OwnedServiceRegistry(
            new ModuleIdentifier('owner'),
        );
    }
}
