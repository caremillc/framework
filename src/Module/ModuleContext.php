<?php

declare(strict_types=1);

namespace Careminate\Module;

use Careminate\Application\ApplicationEnvironment;
use Careminate\Application\ApplicationPaths;
use Careminate\Contracts\Container\ContainerInterface;
use Careminate\Contracts\Module\ServiceRegistryInterface;

final readonly class ModuleContext
{
    public function __construct(
        public ModuleDefinition $definition,
        public ContainerInterface $container,
        public ServiceRegistryInterface $services,
        public CapabilityRegistry $capabilities,
        public ApplicationEnvironment $environment,
        public ApplicationPaths $paths,
        public bool $compiled,
    ) {
    }
}