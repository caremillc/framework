<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Contracts\Module\ModuleInterface;
use Careminate\Exception\Module\CircularModuleDependencyException;
use Careminate\Exception\Module\DisabledModuleDependencyException;
use Careminate\Module\CapabilityRegistry;
use Careminate\Module\ModuleContext;
use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleDependencyGraph;
use Careminate\Module\ModuleRegistry;
use PHPUnit\Framework\TestCase;

final class ModuleDependencyGraphTest extends TestCase
{
    public function test_dependencies_are_ordered_before_consumers(): void
    {
        $registry = new ModuleRegistry();
        $registry->register(GraphBillingModule::class);
        $registry->register(GraphUsersModule::class);
        $registry->freeze();

        $plan = (new ModuleDependencyGraph())->plan(
            $registry,
            CapabilityRegistry::fromModules($registry),
        );

        self::assertSame(
            [GraphUsersModule::class, GraphBillingModule::class],
            $plan->orderedModules,
        );
    }

    public function test_disabled_required_dependency_is_rejected(): void
    {
        $registry = new ModuleRegistry();
        $registry->register(GraphUsersModule::class);
        $registry->register(GraphBillingModule::class);
        $registry->disable('graph-users');
        $registry->freeze();

        $this->expectException(DisabledModuleDependencyException::class);

        (new ModuleDependencyGraph())->plan(
            $registry,
            CapabilityRegistry::fromModules($registry),
        );
    }

    public function test_cycles_are_rejected(): void
    {
        $registry = new ModuleRegistry();
        $registry->register(GraphCycleOneModule::class);
        $registry->register(GraphCycleTwoModule::class);
        $registry->freeze();

        $this->expectException(CircularModuleDependencyException::class);

        (new ModuleDependencyGraph())->plan(
            $registry,
            CapabilityRegistry::fromModules($registry),
        );
    }
}

final class GraphUsersModule implements ModuleInterface
{
    public static function definition(): ModuleDefinition
    {
        return ModuleDefinition::named('graph-users')->version('1.1.0');
    }

    public function register(ModuleContext $context): void
    {
    }

    public function boot(ModuleContext $context): void
    {
    }
}

final class GraphBillingModule implements ModuleInterface
{
    public static function definition(): ModuleDefinition
    {
        return ModuleDefinition::named('graph-billing')
            ->version('1.0.0')
            ->requires(GraphUsersModule::class, '^1.0');
    }

    public function register(ModuleContext $context): void
    {
    }

    public function boot(ModuleContext $context): void
    {
    }
}

final class GraphCycleOneModule implements ModuleInterface
{
    public static function definition(): ModuleDefinition
    {
        return ModuleDefinition::named('graph-cycle-one')
            ->version('1.0.0')
            ->requires(GraphCycleTwoModule::class);
    }

    public function register(ModuleContext $context): void
    {
    }

    public function boot(ModuleContext $context): void
    {
    }
}

final class GraphCycleTwoModule implements ModuleInterface
{
    public static function definition(): ModuleDefinition
    {
        return ModuleDefinition::named('graph-cycle-two')
            ->version('1.0.0')
            ->requires(GraphCycleOneModule::class);
    }

    public function register(ModuleContext $context): void
    {
    }

    public function boot(ModuleContext $context): void
    {
    }
}
