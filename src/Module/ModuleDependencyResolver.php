<?php

declare(strict_types=1);

namespace Careminate\Module;

use Careminate\Module\Exception\ModuleResolutionException;
use LogicException;

/**
 * Resolves enabled modules into deterministic dependency-first order.
 *
 * @api
 */
final class ModuleDependencyResolver
{
    /**
     * @param iterable<ModuleDefinition> $definitions
     * @param iterable<ModuleIdentifier> $disabled
     *
     * @return list<ModuleDefinition>
     */
    public function resolve(
        iterable $definitions,
        iterable $disabled = [],
    ): array {
        /** @var array<string, ModuleDefinition> $modules */
        $modules = [];

        foreach ($definitions as $definition) {
            $name = $definition->id->value;

            if (isset($modules[$name])) {
                throw new ModuleResolutionException(
                    'A module identifier is defined more than once.',
                    [$name],
                );
            }

            $modules[$name] = $definition;
        }

        ksort($modules, SORT_STRING);

        /** @var array<string, true> $disabledNames */
        $disabledNames = [];

        foreach ($disabled as $identifier) {
            $disabledNames[$identifier->value] = true;
        }

        ksort($disabledNames, SORT_STRING);

        foreach ($disabledNames as $name => $unused) {
            if (!isset($modules[$name])) {
                throw new ModuleResolutionException(
                    'A disabled module identifier is not defined.',
                    [$name],
                );
            }
        }

        $enabled = array_diff_key($modules, $disabledNames);

        /** @var array<string, array<string, true>> $graph */
        $graph = [];

        foreach ($enabled as $name => $definition) {
            /** @var array<string, true> $dependencies */
            $dependencies = [];

            $required = $definition->required;

            usort(
                $required,
                static fn (
                    ModuleIdentifier $first,
                    ModuleIdentifier $second,
                ): int => strcmp($first->value, $second->value),
            );

            foreach ($required as $dependency) {
                $target = $dependency->value;

                if (!isset($modules[$target])) {
                    throw new ModuleResolutionException(
                        'A required module is not defined.',
                        [$name, $target],
                    );
                }

                if (isset($disabledNames[$target])) {
                    throw new ModuleResolutionException(
                        'A required module is disabled.',
                        [$name, $target],
                    );
                }

                $dependencies[$target] = true;
            }

            foreach ($definition->optional as $dependency) {
                $target = $dependency->value;

                if (isset($enabled[$target])) {
                    $dependencies[$target] = true;
                }
            }

            ksort($dependencies, SORT_STRING);
            $graph[$name] = $dependencies;
        }

        $ordered = [];

        while ($graph !== []) {
            $ready = null;

            foreach ($graph as $name => $dependencies) {
                if ($dependencies === []) {
                    $ready = $name;

                    break;
                }
            }

            if ($ready === null) {
                throw new ModuleResolutionException(
                    'The enabled modules contain a dependency cycle.',
                    $this->cyclePath($graph),
                );
            }

            $ordered[] = $enabled[$ready];
            unset($graph[$ready]);

            foreach ($graph as $name => $dependencies) {
                unset($dependencies[$ready]);
                $graph[$name] = $dependencies;
            }
        }

        $this->validateVersions($enabled);

        return $ordered;
    }

    /**
     * @param array<string, ModuleDefinition> $enabled
     */
    private function validateVersions(array $enabled): void
    {
        ksort($enabled, SORT_STRING);

        foreach ($enabled as $name => $definition) {
            $requirements = $definition->dependencyVersions;
            ksort($requirements, SORT_STRING);

            foreach ($requirements as $target => $range) {
                $dependency = $enabled[$target] ?? null;

                if ($dependency === null) {
                    // Required dependencies were validated while building
                    // the graph. Only absent or disabled optional targets
                    // can reach this branch.
                    continue;
                }

                $version = $dependency->version;

                if ($version === null) {
                    throw new ModuleResolutionException(
                        'A constrained module dependency has no declared version.',
                        [$name, $target],
                    );
                }

                if (!$range->contains($version)) {
                    throw new ModuleResolutionException(
                        'A module dependency version does not satisfy its required range.',
                        [$name, $target],
                    );
                }
            }
        }
    }

    /**
     * @param array<string, array<string, true>> $graph
     *
     * @return list<string>
     */
    private function cyclePath(array $graph): array
    {
        $current = array_key_first($graph);

        if ($current === null) {
            throw new LogicException(
                'Cycle diagnostics require an unresolved dependency graph.',
            );
        }

        /** @var array<string, int> $positions */
        $positions = [];

        /** @var list<string> $path */
        $path = [];

        while (!isset($positions[$current])) {
            $positions[$current] = count($path);
            $path[] = $current;

            $next = array_key_first($graph[$current]);

            if ($next === null) {
                throw new LogicException(
                    'An unresolved cycle graph cannot contain a ready module.',
                );
            }

            $current = $next;
        }

        $cycle = array_slice($path, $positions[$current]);
        $cycle[] = $current;

        return $cycle;
    }
}
