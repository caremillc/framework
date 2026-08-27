<?php

declare(strict_types=1);

namespace Careminate\Application\Bootstrap;

use Careminate\Application\ApplicationEnvironment;
use Careminate\Application\ApplicationPaths;
use Careminate\Application\Kernel\KernelRegistry;
use Careminate\Contracts\Container\ContainerInterface;
use Careminate\Contracts\Container\ContainerRegistryInterface;

final readonly class BootstrapContext
{
    public function __construct(
        public ContainerInterface&ContainerRegistryInterface $container,
        public ApplicationEnvironment $environment,
        public ApplicationPaths $paths,
        public KernelRegistry $kernels,
    ) {
    }
}