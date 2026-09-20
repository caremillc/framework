<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Container\Compilation\CompiledDefinitionContainerFactory;
use Careminate\Container\Container;

/**
 * Creates compiled containers with module service boundaries enabled.
 *
 * @internal
 */
final class ModuleBoundaryContainerFactory
{
    /**
     * Every supplied runtime object is explicitly shared with all modules.
     *
     * @param array<string, object> $runtimeObjects
     */
    public function create(
        ModuleServiceDefinitions $snapshot,
        array $runtimeObjects = [],
    ): Container {
        return $this->compose($snapshot, $runtimeObjects)->application;
    }

    /**
     * @param array<string, object> $runtimeObjects
     */
    public function compose(
        ModuleServiceDefinitions $snapshot,
        array $runtimeObjects = [],
    ): ModuleBoundaryContainers {
        $policy = new ModuleResolutionAccessPolicy(
            $snapshot,
            array_keys($runtimeObjects),
        );

        $container = new CompiledDefinitionContainerFactory()->create(
            $snapshot->definitions,
            $runtimeObjects,
            $policy,
        );

        return new ModuleBoundaryContainers($container, $policy);
    }
}
