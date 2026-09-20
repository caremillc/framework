<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Application\ApplicationRunner;
use Careminate\Container\Compilation\CompiledDefinitionContainerFactory;
use Careminate\Module\Exception\ModuleResolutionException;
use Careminate\Module\ModuleBootRegistration;
use Careminate\Module\ModuleIdentifier;
use Careminate\Module\ModuleRegistration;

/**
 * Composes module services and ordered application bootstrappers.
 *
 * @internal
 */
final class ModuleApplicationFactory
{
    /**
     * @param iterable<ModuleRegistration> $registrations
     * @param iterable<ModuleBootRegistration> $bootRegistrations
     * @param iterable<ModuleIdentifier> $disabled
     * @param array<string, object> $runtimeObjects
     */
    public function create(
        iterable $registrations,
        iterable $bootRegistrations = [],
        iterable $disabled = [],
        array $runtimeObjects = [],
        bool $enforceBoundaries = false,
    ): ModuleApplication {
        $modules = [];

        /** @var array<string, true> $known */
        $known = [];

        foreach ($registrations as $registration) {
            $modules[] = $registration;
            $known[$registration->definition->id->value] = true;
        }

        /** @var array<string, ModuleBootRegistration> $boots */
        $boots = [];

        foreach ($bootRegistrations as $registration) {
            $name = $registration->owner->value;

            if (!isset($known[$name])) {
                throw new ModuleResolutionException(
                    'A bootstrapper owner has no module registration.',
                    [$name],
                );
            }

            if (isset($boots[$name])) {
                throw new ModuleResolutionException(
                    'A module has more than one bootstrapper registration.',
                    [$name],
                );
            }

            $boots[$name] = $registration;
        }

        $services = new ModuleServiceCompiler()->compile(
            $modules,
            $disabled,
        );

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

        $adapters = new ModuleScopedBootstrapperFactory();
        $bootstrappers = [];

        foreach ($services->modules as $module) {
            $registration = $boots[$module->id->value] ?? null;

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
