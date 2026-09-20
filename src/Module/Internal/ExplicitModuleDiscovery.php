<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Application\BootstrapperInterface;
use Careminate\Module\Exception\ModuleDiscoveryException;
use Careminate\Module\Exception\ModuleResolutionException;
use Careminate\Module\ModuleBootRegistration;
use Careminate\Module\ModuleDefinition;
use Careminate\Module\ModuleDependencyResolver;
use Careminate\Module\ModuleIdentifier;
use Careminate\Module\ModuleInterface;
use Careminate\Module\ModuleRegistration;
use ReflectionClass;
use Throwable;

/**
 * Discovers trusted module entry points.
 *
 * Indexed discovery excludes disabled classes before autoloading.
 * Legacy class-list discovery reads metadata before selection.
 *
 * @internal
 */
final class ExplicitModuleDiscovery
{
    /**
     * @param iterable<string> $classes
     * @param iterable<ModuleIdentifier> $disabled
     */
    public function discover(
        iterable $classes,
        iterable $disabled = [],
    ): DiscoveredModules {
        $definitions = [];

        /** @var array<string, class-string<ModuleInterface>> $entryPoints */
        $entryPoints = [];

        foreach ($classes as $className) {
            [$entryPoint, $definition] = $this->readDefinition($className);

            $name = $definition->id->value;

            if (isset($entryPoints[$name])) {
                throw new ModuleResolutionException(
                    'A module identifier is defined more than once.',
                    [$name],
                );
            }

            $entryPoints[$name] = $entryPoint;
            $definitions[] = $definition;
        }

        $ordered = new ModuleDependencyResolver()->resolve(
            $definitions,
            $disabled,
        );

        return $this->construct($ordered, $entryPoints);
    }

    /**
     * Discovers a trusted identifier-to-class catalog.
     *
     * Disabled declarations contribute only their identifiers to graph
     * validation. Their classes and runtime metadata are not loaded.
     *
     * @param array<string, string> $classesByModule
     * @param iterable<ModuleIdentifier> $disabled
     */
    public function discoverIndexed(
        array $classesByModule,
        iterable $disabled = [],
    ): DiscoveredModules {
        ksort($classesByModule, SORT_STRING);

        /** @var array<string, ModuleIdentifier> $identifiers */
        $identifiers = [];

        /** @var array<string, true> $seenClasses */
        $seenClasses = [];

        foreach ($classesByModule as $name => $className) {
            $identifier = new ModuleIdentifier($name);

            if (!$this->isClassName($className)) {
                throw new ModuleDiscoveryException(
                    'A module declaration must contain a valid class name.',
                );
            }

            $classKey = strtolower($className);

            if (isset($seenClasses[$classKey])) {
                throw new ModuleDiscoveryException(
                    'A module entry-point class is declared more than once.',
                );
            }

            $seenClasses[$classKey] = true;
            $identifiers[$name] = $identifier;
        }

        /** @var array<string, ModuleIdentifier> $disabledByName */
        $disabledByName = [];

        foreach ($disabled as $identifier) {
            $disabledByName[$identifier->value] = $identifier;
        }

        ksort($disabledByName, SORT_STRING);

        foreach ($disabledByName as $name => $identifier) {
            if (!isset($identifiers[$name])) {
                throw new ModuleResolutionException(
                    'A disabled module identifier is not defined.',
                    [$name],
                );
            }
        }

        $definitions = [];

        /** @var array<string, class-string<ModuleInterface>> $entryPoints */
        $entryPoints = [];

        foreach ($classesByModule as $name => $className) {
            if (isset($disabledByName[$name])) {
                // Identity-only graph nodes preserve disabled-dependency
                // diagnostics without reading disabled module metadata.
                $definitions[] = new ModuleDefinition($identifiers[$name]);

                continue;
            }

            [$entryPoint, $definition] = $this->readDefinition($className);

            if ($definition->id->value !== $name) {
                throw new ModuleDiscoveryException(
                    'Module metadata does not match its indexed identifier.',
                );
            }

            $entryPoints[$name] = $entryPoint;
            $definitions[] = $definition;
        }

        $ordered = new ModuleDependencyResolver()->resolve(
            $definitions,
            array_values($disabledByName),
        );

        return $this->construct($ordered, $entryPoints);
    }

    /**
     * @return array{class-string<ModuleInterface>, ModuleDefinition}
     */
    private function readDefinition(string $className): array
    {
        try {
            if (
                !class_exists($className)
                || !is_subclass_of($className, ModuleInterface::class)
            ) {
                throw new ModuleDiscoveryException(
                    'A module entry point must implement ModuleInterface.',
                );
            }

            return [$className, $className::definition()];
        } catch (Throwable $previous) {
            throw new ModuleDiscoveryException(
                message: 'Module metadata discovery failed.',
                previous: $previous,
            );
        }
    }

    /**
     * @param list<ModuleDefinition> $ordered
     * @param array<string, class-string<ModuleInterface>> $entryPoints
     */
    private function construct(
        array $ordered,
        array $entryPoints,
    ): DiscoveredModules {
        new ModuleCapabilityValidator()->validate($ordered);

        // Validate every enabled constructor before constructing any module.
        foreach ($ordered as $definition) {
            try {
                $reflection = new ReflectionClass(
                    $entryPoints[$definition->id->value],
                );

                $constructor = $reflection->getConstructor();

                if (
                    !$reflection->isInstantiable()
                    || (
                        $constructor !== null
                        && $constructor->getNumberOfRequiredParameters() > 0
                    )
                ) {
                    throw new ModuleDiscoveryException(
                        'An enabled module must be instantiable without arguments.',
                    );
                }
            } catch (Throwable $previous) {
                throw new ModuleDiscoveryException(
                    message: 'Module construction validation failed.',
                    previous: $previous,
                );
            }
        }

        $registrations = [];
        $bootRegistrations = [];

        foreach ($ordered as $definition) {
            $className = $entryPoints[$definition->id->value];

            try {
                $module = new $className();
            } catch (Throwable $previous) {
                throw new ModuleDiscoveryException(
                    message: 'An enabled module could not be constructed.',
                    previous: $previous,
                );
            }

            $registrations[] = new ModuleRegistration($definition, $module);

            if ($module instanceof BootstrapperInterface) {
                $bootRegistrations[] = new ModuleBootRegistration(
                    $definition->id,
                    $module,
                );
            }
        }

        return new DiscoveredModules($registrations, $bootRegistrations);
    }

    private function isClassName(string $className): bool
    {
        foreach (explode('\\', $className) as $segment) {
            if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $segment) !== 1) {
                return false;
            }
        }

        return true;
    }
}
