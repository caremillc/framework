<?php

declare(strict_types=1);

namespace CareminateIntegration\Tests\Fixtures\Module;

use Careminate\Application\TerminableBootstrapperInterface;
use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleIdentifier;
use Careminate\Module\ModuleInterface;
use Careminate\Module\ServiceRegistryInterface;
use Psr\Container\ContainerInterface;

final class DiscoverableModule implements
    ModuleInterface,
    TerminableBootstrapperInterface
{
    /**
     * @var list<string>
     */
    public array $events = [];

    public static function definition(): ModuleDefinition
    {
        return new ModuleDefinition(new ModuleIdentifier('discovered'));
    }

    public function register(ServiceRegistryInterface $services): void
    {
        $this->events[] = 'register';
        $services->value('discovered.value', 'ready');
    }

    public function bootstrap(ContainerInterface $container): void
    {
        $this->events[] = 'boot';
    }

    public function terminate(ContainerInterface $container): void
    {
        $this->events[] = 'terminate';
    }
}
