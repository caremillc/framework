<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Container\Compilation\DefinitionBuilder;
use Careminate\Container\Compilation\ServiceDefinition;
use Careminate\Module\Exception\InvalidModuleBoundaryException;
use Careminate\Module\Internal\ModuleServiceAccessPolicy;
use Careminate\Module\Internal\ModuleServiceDefinitions;
use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleIdentifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleServiceAccessPolicyTest extends TestCase
{
    #[DataProvider('accessCases')]
    public function testAccessRequiresOwnershipOrAnExportedDirectDependency(
        string $requester,
        string $serviceId,
        bool $expected,
    ): void {
        $policy = new ModuleServiceAccessPolicy(
            self::snapshot(),
            ['base.public'],
        );

        self::assertSame(
            $expected,
            $policy->allows(
                new ModuleIdentifier($requester),
                $serviceId,
            ),
        );
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function accessCases(): iterable
    {
        yield 'owner private access' => ['base', 'base.private', true];
        yield 'owner exported access' => ['base', 'base.public', true];
        yield 'required dependency export' => ['middle', 'base.public', true];
        yield 'required dependency private' => ['middle', 'base.private', false];
        yield 'optional dependency export' => ['optional', 'base.public', true];
        yield 'optional dependency private' => ['optional', 'base.private', false];
        yield 'transitive dependency denied' => ['consumer', 'base.public', false];
        yield 'unrelated module denied' => ['stranger', 'base.public', false];
        yield 'reverse dependency denied' => ['base', 'middle.private', false];
        yield 'unknown requester denied' => ['unknown', 'base.public', false];
        yield 'unknown service denied' => ['base', 'missing', false];
        yield 'ambient service denied' => ['middle', 'runtime.container', false];
    }

    public function testServicesArePrivateWhenNoExportsAreSupplied(): void
    {
        $policy = new ModuleServiceAccessPolicy(self::snapshot());

        self::assertTrue(
            $policy->allows(
                new ModuleIdentifier('base'),
                'base.public',
            ),
        );

        self::assertFalse(
            $policy->allows(
                new ModuleIdentifier('middle'),
                'base.public',
            ),
        );
    }

    public function testRepeatedExportsAreIdempotent(): void
    {
        $policy = new ModuleServiceAccessPolicy(
            self::snapshot(),
            ['base.public', 'base.public'],
        );

        self::assertTrue(
            $policy->allows(
                new ModuleIdentifier('middle'),
                'base.public',
            ),
        );
    }

    public function testUnknownServiceCannotBeExported(): void
    {
        $this->expectException(InvalidModuleBoundaryException::class);

        new ModuleServiceAccessPolicy(
            self::snapshot(),
            ['missing'],
        );
    }

    public function testServiceWithoutOwnershipIsRejected(): void
    {
        $builder = new DefinitionBuilder();
        $builder->add(ServiceDefinition::forValue('unowned', null));

        $snapshot = new ModuleServiceDefinitions(
            $builder->build(),
            [new ModuleDefinition(new ModuleIdentifier('base'))],
            [],
        );

        $this->expectException(InvalidModuleBoundaryException::class);

        new ModuleServiceAccessPolicy($snapshot);
    }

    public function testServiceOwnerMustBelongToTheSnapshot(): void
    {
        $builder = new DefinitionBuilder();
        $builder->add(ServiceDefinition::forValue('service', null));

        $snapshot = new ModuleServiceDefinitions(
            $builder->build(),
            [new ModuleDefinition(new ModuleIdentifier('base'))],
            ['entry:service' => new ModuleIdentifier('absent')],
        );

        $this->expectException(InvalidModuleBoundaryException::class);

        new ModuleServiceAccessPolicy($snapshot);
    }

    public function testPrefixLikeServiceNamesRemainDistinct(): void
    {
        $owner = new ModuleIdentifier('base');
        $requester = new ModuleIdentifier('consumer');
        $builder = new DefinitionBuilder();

        $builder->add(ServiceDefinition::forValue('service', null));
        $builder->add(ServiceDefinition::forValue('entry:service', null));

        $snapshot = new ModuleServiceDefinitions(
            $builder->build(),
            [
                new ModuleDefinition($owner),
                new ModuleDefinition($requester, required: [$owner]),
            ],
            [
                'entry:service' => $owner,
                'entry:entry:service' => $owner,
            ],
        );

        $policy = new ModuleServiceAccessPolicy(
            $snapshot,
            ['entry:service'],
        );

        self::assertFalse($policy->allows($requester, 'service'));
        self::assertTrue($policy->allows($requester, 'entry:service'));
    }

    private static function snapshot(): ModuleServiceDefinitions
    {
        $base = new ModuleIdentifier('base');
        $middle = new ModuleIdentifier('middle');

        $builder = new DefinitionBuilder();
        $builder->add(ServiceDefinition::forValue('base.private', null));
        $builder->add(ServiceDefinition::forValue('base.public', null));
        $builder->add(ServiceDefinition::forValue('middle.private', null));

        return new ModuleServiceDefinitions(
            $builder->build(),
            [
                new ModuleDefinition($base),
                new ModuleDefinition($middle, required: [$base]),
                new ModuleDefinition(
                    new ModuleIdentifier('consumer'),
                    required: [$middle],
                ),
                new ModuleDefinition(
                    new ModuleIdentifier('optional'),
                    optional: [$base],
                ),
                new ModuleDefinition(new ModuleIdentifier('stranger')),
            ],
            [
                'entry:base.private' => $base,
                'entry:base.public' => $base,
                'entry:middle.private' => $middle,
            ],
        );
    }
}
