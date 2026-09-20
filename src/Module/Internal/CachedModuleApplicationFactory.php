<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Application\ApplicationRunner;
use Careminate\Container\Compilation\CompiledDefinitionContainerFactory;
use Careminate\Module\Exception\ModuleCacheException;
use Careminate\Module\ModuleIdentifier;

/**
 * Reconstructs an application from a validated module artifact.
 *
 * @internal
 */
final readonly class CachedModuleApplicationFactory
{
    public function __construct(
        private FilesystemModuleArtifactStore $store,
    ) {
    }

    /**
     * @param iterable<string> $classes
     * @param iterable<ModuleIdentifier> $disabled
     * @param array<string, object> $runtimeObjects
     */
    public function create(
        string $key,
        ModuleCacheIdentity $expectedIdentity,
        string $expectedFingerprint,
        iterable $classes,
        iterable $disabled = [],
        array $runtimeObjects = [],
        bool $enforceBoundaries = false,
    ): ModuleApplication {
        $services = $this->store->load(
            $key,
            $expectedIdentity,
            $expectedFingerprint,
        );

        $discovered = new ExplicitModuleDiscovery()->discover(
            $classes,
            $disabled,
        );

        return $this->compose(
            $services,
            $discovered,
            $runtimeObjects,
            $enforceBoundaries,
        );
    }

    /**
     * @param array<string, string> $classesByModule
     * @param iterable<ModuleIdentifier> $disabled
     * @param array<string, object> $runtimeObjects
     */
    public function createIndexed(
        string $key,
        ModuleCacheIdentity $expectedIdentity,
        string $expectedFingerprint,
        array $classesByModule,
        iterable $disabled = [],
        array $runtimeObjects = [],
        bool $enforceBoundaries = false,
    ): ModuleApplication {
        $services = $this->store->load(
            $key,
            $expectedIdentity,
            $expectedFingerprint,
        );

        $discovered = new ExplicitModuleDiscovery()->discoverIndexed(
            $classesByModule,
            $disabled,
        );

        return $this->compose(
            $services,
            $discovered,
            $runtimeObjects,
            $enforceBoundaries,
        );
    }

    /**
     * @param array<string, object> $runtimeObjects
     */
    private function compose(
        ModuleServiceDefinitions $services,
        DiscoveredModules $discovered,
        array $runtimeObjects,
        bool $enforceBoundaries,
    ): ModuleApplication {
        $definitions = [];

        foreach ($discovered->registrations as $registration) {
            $definitions[] = $registration->definition;
        }

        $metadata = new ModuleMetadataCodec();

        if (
            $metadata->encode($services->modules)
            !== $metadata->encode($definitions)
        ) {
            throw new ModuleCacheException(
                'Cached module metadata does not match the selected entry points.',
            );
        }

        $boundaryContainers = $enforceBoundaries
            ? new ModuleBoundaryContainerFactory()->compose(
                $services,
                $runtimeObjects,
            )
            : null;

        $container = $boundaryContainers->application
            ?? new CompiledDefinitionContainerFactory()->create(
                $services->definitions,
                $runtimeObjects,
            );

        $bootRegistrations = [];

        foreach ($discovered->bootRegistrations as $registration) {
            $bootRegistrations[$registration->owner->value] = $registration;
        }

        $adapters = new ModuleScopedBootstrapperFactory();
        $bootstrappers = [];

        foreach ($services->modules as $module) {
            $registration = $bootRegistrations[$module->id->value] ?? null;

            if ($registration === null) {
                continue;
            }

            $moduleContainer = $boundaryContainers?->forModule($module->id);

            foreach ($registration->bootstrappers as $bootstrapper) {
                $bootstrappers[] = $moduleContainer === null
                    ? $bootstrapper
                    : $adapters->wrap($bootstrapper, $moduleContainer);
            }
        }

        return new ModuleApplication(
            new ApplicationRunner($container, ...$bootstrappers),
            $services,
        );
    }
}
