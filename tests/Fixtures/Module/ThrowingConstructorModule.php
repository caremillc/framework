<?php

declare(strict_types=1);

namespace CareminateIntegration\Tests\Fixtures\Module;

use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleIdentifier;
use Careminate\Module\ModuleInterface;
use Careminate\Module\ServiceRegistryInterface;
use Error;

final class ThrowingConstructorModule implements ModuleInterface
{
    public function __construct()
    {
        throw new Error('Module constructor failed.');
    }

    public static function definition(): ModuleDefinition
    {
        return new ModuleDefinition(new ModuleIdentifier('throwing'));
    }

    public function register(ServiceRegistryInterface $services): void
    {
        $services->value('unreachable', true);
    }
}
