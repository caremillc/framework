<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Module;

use Careminate\Contracts\Module\ModuleInterface;
use Careminate\Exception\Module\DuplicateCapabilityProviderException;
use Careminate\Module\CapabilityRegistry;
use Careminate\Module\ModuleContext;
use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleRegistry;
use PHPUnit\Framework\TestCase;

final class CapabilityRegistryTest extends TestCase
{
    public function test_duplicate_capability_providers_are_rejected(): void
    {
        $registry = new ModuleRegistry();
        $registry->register(FirstMailModule::class);
        $registry->register(SecondMailModule::class);
        $registry->freeze();

        $this->expectException(
            DuplicateCapabilityProviderException::class,
        );

        CapabilityRegistry::fromModules($registry);
    }
}

final class FirstMailModule implements ModuleInterface
{
    public static function definition(): ModuleDefinition
    {
        return ModuleDefinition::named('first-mail')
            ->version('1.0.0')
            ->provides('mail.sender');
    }

    public function register(ModuleContext $context): void
    {
    }

    public function boot(ModuleContext $context): void
    {
    }
}

final class SecondMailModule implements ModuleInterface
{
    public static function definition(): ModuleDefinition
    {
        return ModuleDefinition::named('second-mail')
            ->version('1.0.0')
            ->provides('mail.sender');
    }

    public function register(ModuleContext $context): void
    {
    }

    public function boot(ModuleContext $context): void
    {
    }
}
