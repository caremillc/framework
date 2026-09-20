<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Module\Exception\InvalidModuleDefinitionException;
use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleIdentifier;
use Careminate\Module\ModuleVersion;
use Careminate\Module\ModuleVersionRange;
use PHPUnit\Framework\TestCase;

final class ModuleVersionDeclarationTest extends TestCase
{
    public function testExistingDeclarationsRemainUnversioned(): void
    {
        $module = new ModuleDefinition(
            new ModuleIdentifier('consumer'),
            [new ModuleIdentifier('base')],
            [new ModuleIdentifier('optional')],
            [],
            [],
        );

        self::assertNull($module->version);
        self::assertSame([], $module->dependencyVersions);
        self::assertSame('base', $module->required[0]->value);
        self::assertSame('optional', $module->optional[0]->value);
    }

    public function testRequirementsCanConstrainRequiredAndOptionalDependencies(): void
    {
        $version = new ModuleVersion('2.0.0');
        $requiredRange = ModuleVersionRange::atLeast(
            new ModuleVersion('1.0.0'),
        );
        $optionalRange = ModuleVersionRange::exactly(
            new ModuleVersion('3.0.0'),
        );

        $module = new ModuleDefinition(
            new ModuleIdentifier('consumer'),
            required: [new ModuleIdentifier('base')],
            optional: [new ModuleIdentifier('extension')],
            version: $version,
            dependencyVersions: [
                'base' => $requiredRange,
                'extension' => $optionalRange,
            ],
        );

        self::assertSame($version, $module->version);
        self::assertSame($requiredRange, $module->dependencyVersions['base']);
        self::assertSame(
            $optionalRange,
            $module->dependencyVersions['extension'],
        );
    }

    public function testRequirementCannotIntroduceAnUndeclaredDependency(): void
    {
        $this->expectException(InvalidModuleDefinitionException::class);

        new ModuleDefinition(
            new ModuleIdentifier('consumer'),
            dependencyVersions: [
                'undeclared' => ModuleVersionRange::any(),
            ],
        );
    }

    public function testRequirementCannotTargetTheModuleItself(): void
    {
        $this->expectException(InvalidModuleDefinitionException::class);

        new ModuleDefinition(
            new ModuleIdentifier('consumer'),
            dependencyVersions: [
                'consumer' => ModuleVersionRange::any(),
            ],
        );
    }
}
