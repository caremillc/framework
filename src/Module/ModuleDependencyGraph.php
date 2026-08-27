<?php

declare(strict_types=1);

namespace Careminate\Module;

use Composer\Semver\Semver;
use Careminate\Contracts\Module\ModuleInterface;
use Careminate\Exception\Module\CircularModuleDependencyException;
use Careminate\Exception\Module\DisabledModuleDependencyException;
use Careminate\Exception\Module\IncompatibleModuleVersionException;
use Careminate\Exception\Module\InvalidModuleException;
use Careminate\Exception\Module\MissingCapabilityException;
use Careminate\Exception\Module\MissingModuleDependencyException;

final class ModuleDependencyGraph
{
    public function plan(
        ModuleRegistry $registry,
        CapabilityRegistry $capabilities,
    ): ModulePlan {
        /** @var array<class-string<ModuleInterface>, RegisteredModule> $active */
        $active = [];

        foreach ($registry->active() as $module) {
            $active[$module->class] = $module;
        }

        /** @var array<class-string<ModuleInterface>, list<class-string<ModuleInterface>>> $edges */
        $edges = [];

        foreach ($active as $module) {
            $edges[$module->class] = [];

            foreach ($module->definition->requiredModules as $dependency) {
                $edges[$module->class][] = $this->resolveModuleDependency(
                    $module,
                    $dependency,
                    $registry,
                    true,
                );
            }

            foreach ($module->definition->optionalModules as $dependency) {
                $resolved = $this->resolveModuleDependency(
                    $module,
                    $dependency,
                    $registry,
                    false,
                );

                if ($resolved !== null) {
                    $edges[$module->class][] = $resolved;
                }
            }

            foreach ($module->definition->requiredCapabilities as $capability) {
                $provider = $capabilities->providerClass($capability);

                if ($provider === null) {
                    throw new MissingCapabilityException(
                        $module->definition->name,
                        $capability,
                    );
                }

                if ($provider !== $module->class) {
                    $edges[$module->class][] = $provider;
                }
            }

            foreach ($module->definition->optionalCapabilities as $capability) {
                $provider = $capabilities->providerClass($capability);

                if ($provider !== null && $provider !== $module->class) {
                    $edges[$module->class][] = $provider;
                }
            }

            $edges[$module->class] = array_values(array_unique(
                $edges[$module->class],
            ));

            usort(
                $edges[$module->class],
                static fn (string $left, string $right): int =>
                    $active[$left]->definition->name
                    <=> $active[$right]->definition->name,
            );
        }

        /** @var array<class-string<ModuleInterface>, 1|2> $states */
        $states = [];

        /** @var list<class-string<ModuleInterface>> $stack */
        $stack = [];

        /** @var list<class-string<ModuleInterface>> $ordered */
        $ordered = [];

        $visit = function (string $class) use (
            &$visit,
            &$states,
            &$stack,
            &$ordered,
            $edges,
            $active,
        ): void {
            if (($states[$class] ?? null) === 2) {
                return;
            }

            if (($states[$class] ?? null) === 1) {
                $position = array_search($class, $stack, true);
                $cycleClasses = array_slice(
                    $stack,
                    is_int($position) ? $position : 0,
                );
                $cycleClasses[] = $class;

                $cycleNames = array_map(
                    static fn (string $cycleClass): string =>
                        $active[$cycleClass]->definition->name,
                    $cycleClasses,
                );

                throw new CircularModuleDependencyException($cycleNames);
            }

            $states[$class] = 1;
            $stack[] = $class;

            foreach ($edges[$class] as $dependency) {
                $visit($dependency);
            }

            array_pop($stack);
            $states[$class] = 2;
            $ordered[] = $class;
        };

        $classes = array_keys($active);

        usort(
            $classes,
            static fn (string $left, string $right): int =>
                $active[$left]->definition->name
                <=> $active[$right]->definition->name,
        );

        foreach ($classes as $class) {
            $visit($class);
        }

        return new ModulePlan(
            $ordered,
            $registry->disabledNames(),
            $registry->fingerprint(),
        );
    }

    /**
     * @return class-string<ModuleInterface>|null
     */
    private function resolveModuleDependency(
        RegisteredModule $source,
        ModuleDependency $dependency,
        ModuleRegistry $registry,
        bool $required,
    ): ?string {
        if ($dependency->module === $source->class) {
            throw new InvalidModuleException(sprintf(
                'Module "%s" cannot depend on itself.',
                $source->definition->name,
            ));
        }

        $target = $registry->find($dependency->module);

        if ($target === null) {
            if (!$required) {
                return null;
            }

            throw new MissingModuleDependencyException(
                $source->definition->name,
                $dependency->module,
            );
        }

        if ($registry->isDisabled($target->definition->name)) {
            if (!$required) {
                return null;
            }

            throw new DisabledModuleDependencyException(
                $source->definition->name,
                $target->definition->name,
            );
        }

        if (!Semver::satisfies(
            $target->definition->version,
            $dependency->constraint,
        )) {
            throw new IncompatibleModuleVersionException(
                $source->definition->name,
                $target->definition->name,
                $target->definition->version,
                $dependency->constraint,
            );
        }

        return $target->class;
    }
}
