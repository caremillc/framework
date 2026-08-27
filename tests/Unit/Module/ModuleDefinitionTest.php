<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Contracts\Module\ModuleInterface;
use Careminate\Module\ModuleContext;
use Careminate\Module\ModuleDefinition;
use PHPUnit\Framework\TestCase;

final class ModuleDefinitionTest extends TestCase
{
    public function test_it_builds_an_immutable_definition(): void
    {
        $initial = ModuleDefinition::named('billing');

        $configured = $initial
            ->version('1.2.0')
            ->requires(DefinitionUsersModule::class, '^1.0')
            ->provides('payments');

        self::assertSame('0.0.0', $initial->version);
        self::assertSame('1.2.0', $configured->version);
        self::assertSame('payments', $configured->providedCapabilities[0]);
        self::assertSame(
            DefinitionUsersModule::class,
            $configured->requiredModules[0]->module,
        );
    }
}

final class DefinitionUsersModule implements ModuleInterface
{
    public static function definition(): ModuleDefinition
    {
        return ModuleDefinition::named('definition-users')
            ->version('1.0.0');
    }

    public function register(ModuleContext $context): void
    {
    }

    public function boot(ModuleContext $context): void
    {
    }
}
