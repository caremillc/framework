<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Container\Compilation\DefinitionBuilder;
use Careminate\Container\Compilation\ServiceDefinition;
use Careminate\Module\Exception\InvalidModuleBoundaryException;
use Careminate\Module\Internal\ModuleResolutionAccessPolicy;
use Careminate\Module\Internal\ModuleServiceDefinitions;
use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleIdentifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleResolutionAccessPolicyTest extends TestCase
{
    #[DataProvider('accessCases')]
    public function testServiceContextDeterminesAccess(
        ?string $requester,
        string $target,
        bool $expected,
    ): void {
        $policy = new ModuleResolutionAccessPolicy(
            self::snapshot(),
            ['runtime.clock'],
        );

        self::assertSame(
            $expected,
            $policy->allows($requester, $target),
        );
    }

    /**
     * @return iterable<string, array{string|null, string, bool}>
     */
    public static function accessCases(): iterable
    {
        yield 'application export' => [null, 'base.public', true];
        yield 'application private service' => [null, 'base.private', false];
        yield 'application runtime service' => [null, 'runtime.clock', true];
        yield 'application unknown service' => [null, 'missing', false];

        yield 'owner private dependency' => [
            'base.public',
            'base.private',
            true,
        ];
        yield 'direct dependency export' => [
            'consumer.service',
            'base.public',
            true,
        ];
        yield 'direct dependency private service' => [
            'consumer.service',
            'base.private',
            false,
        ];
        yield 'unrelated service requester' => [
            'stranger.service',
            'base.public',
            false,
        ];
        yield 'module runtime service' => [
            'consumer.service',
            'runtime.clock',
            true,
        ];
        yield 'undeclared runtime target' => [
            'consumer.service',
            'runtime.secret',
            false,
        ];
        yield 'unknown requester exported target' => [
            'unknown',
            'base.public',
            false,
        ];
        yield 'unknown requester runtime target' => [
            'unknown',
            'runtime.clock',
            false,
        ];
        yield 'runtime identifier cannot impersonate module' => [
            'runtime.clock',
            'base.private',
            false,
        ];
        yield 'module identifier is not service identity' => [
            'base',
            'base.private',
            false,
        ];
        yield 'internal prefix is not accepted as identity' => [
            'entry:base.public',
            'base.private',
            false,
        ];
    }

    public function testPrivateServicesAreDeniedAtApplicationLevelByDefault(): void
    {
        $snapshot = self::snapshot();

        $privateSnapshot = new ModuleServiceDefinitions(
            $snapshot->definitions,
            $snapshot->modules,
            self::owners(),
        );

        $policy = new ModuleResolutionAccessPolicy($privateSnapshot);

        self::assertFalse($policy->allows(null, 'base.public'));
        self::assertTrue(
            $policy->allows('base.public', 'base.private'),
        );
    }

    public function testRuntimeServicesRequireExplicitDeclaration(): void
    {
        $policy = new ModuleResolutionAccessPolicy(self::snapshot());

        self::assertFalse($policy->allows(null, 'runtime.clock'));
        self::assertFalse(
            $policy->allows('consumer.service', 'runtime.clock'),
        );
    }

    public function testRuntimeDeclarationCannotExposeAnOwnedPrivateService(): void
    {
        $this->expectException(InvalidModuleBoundaryException::class);

        new ModuleResolutionAccessPolicy(
            self::snapshot(),
            ['base.private'],
        );
    }

    public function testRuntimeIdentifierCannotBeEmpty(): void
    {
        $this->expectException(InvalidModuleBoundaryException::class);

        new ModuleResolutionAccessPolicy(self::snapshot(), ['']);
    }

    public function testDuplicateRuntimeDeclarationsAreIdempotent(): void
    {
        $policy = new ModuleResolutionAccessPolicy(
            self::snapshot(),
            ['runtime.clock', 'runtime.clock'],
        );

        self::assertTrue($policy->allows(null, 'runtime.clock'));
        self::assertTrue(
            $policy->allows('consumer.service', 'runtime.clock'),
        );
    }

    private static function snapshot(): ModuleServiceDefinitions
    {
        $builder = new DefinitionBuilder();

        foreach ([
            'base.public',
            'base.private',
            'consumer.service',
            'stranger.service',
        ] as $id) {
            $builder->add(ServiceDefinition::forValue($id, null));
        }

        return new ModuleServiceDefinitions(
            $builder->build(),
            [
                new ModuleDefinition(new ModuleIdentifier('base')),
                new ModuleDefinition(
                    new ModuleIdentifier('consumer'),
                    required: [new ModuleIdentifier('base')],
                ),
                new ModuleDefinition(new ModuleIdentifier('stranger')),
            ],
            self::owners(),
            ['base.public'],
        );
    }

    /**
     * @return array<string, ModuleIdentifier>
     */
    private static function owners(): array
    {
        return [
            'entry:base.public' => new ModuleIdentifier('base'),
            'entry:base.private' => new ModuleIdentifier('base'),
            'entry:consumer.service' => new ModuleIdentifier('consumer'),
            'entry:stranger.service' => new ModuleIdentifier('stranger'),
        ];
    }
}
