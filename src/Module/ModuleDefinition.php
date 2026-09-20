<?php

declare(strict_types=1);

namespace Careminate\Module;

use Careminate\Module\Exception\InvalidModuleDefinitionException;

/**
 * Immutable module identity, dependencies, and capability declarations.
 *
 * @api
 */
final readonly class ModuleDefinition
{
    /**
     * @var list<ModuleIdentifier>
     */
    public array $required;

    /**
     * @var list<ModuleIdentifier>
     */
    public array $optional;

    /**
     * @var list<CapabilityIdentifier>
     */
    public array $requiredCapabilities;

    /**
     * @var list<CapabilityProvision>
     */
    public array $providedCapabilities;

    /**
     * @var array<string, ModuleVersionRange>
     */
    public array $dependencyVersions;

    /**
     * @param list<ModuleIdentifier> $required
     * @param list<ModuleIdentifier> $optional
     * @param list<CapabilityIdentifier> $requiredCapabilities
     * @param list<CapabilityProvision> $providedCapabilities
     * @param array<string, ModuleVersionRange> $dependencyVersions
     */
    public function __construct(
        public ModuleIdentifier $id,
        array $required = [],
        array $optional = [],
        array $requiredCapabilities = [],
        array $providedCapabilities = [],
        public ?ModuleVersion $version = null,
        array $dependencyVersions = [],
    ) {
        $this->required = $this->validateDependencies($required);
        $this->optional = $this->validateDependencies($optional);

        $requiredNames = [];
        $dependencyNames = [];

        foreach ($this->required as $dependency) {
            $requiredNames[$dependency->value] = true;
            $dependencyNames[$dependency->value] = true;
        }

        foreach ($this->optional as $dependency) {
            if (isset($requiredNames[$dependency->value])) {
                throw new InvalidModuleDefinitionException(
                    'A dependency cannot be both required and optional.',
                );
            }

            $dependencyNames[$dependency->value] = true;
        }

        foreach ($dependencyVersions as $name => $range) {
            if (!isset($dependencyNames[$name])) {
                throw new InvalidModuleDefinitionException(
                    'A version requirement must name a declared module dependency.',
                );
            }
        }

        $seenRequirements = [];

        foreach ($requiredCapabilities as $capability) {
            if (isset($seenRequirements[$capability->value])) {
                throw new InvalidModuleDefinitionException(
                    'A required capability cannot be declared more than once.',
                );
            }

            $seenRequirements[$capability->value] = true;
        }

        $seenProvisions = [];

        foreach ($providedCapabilities as $provision) {
            $name = $provision->capability->value;

            if (isset($seenProvisions[$name])) {
                throw new InvalidModuleDefinitionException(
                    'A provided capability cannot be declared more than once.',
                );
            }

            $seenProvisions[$name] = true;
        }

        $this->requiredCapabilities = $requiredCapabilities;
        $this->providedCapabilities = $providedCapabilities;
        $this->dependencyVersions = $dependencyVersions;
    }

    /**
     * @param list<ModuleIdentifier> $dependencies
     *
     * @return list<ModuleIdentifier>
     */
    private function validateDependencies(array $dependencies): array
    {
        $seen = [];

        foreach ($dependencies as $dependency) {
            if ($this->id->equals($dependency)) {
                throw new InvalidModuleDefinitionException(
                    'A module cannot depend on itself.',
                );
            }

            if (isset($seen[$dependency->value])) {
                throw new InvalidModuleDefinitionException(
                    'A dependency cannot be declared more than once.',
                );
            }

            $seen[$dependency->value] = true;
        }

        return $dependencies;
    }
}
