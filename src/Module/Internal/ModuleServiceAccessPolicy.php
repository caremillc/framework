<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Module\Exception\InvalidModuleBoundaryException;
use Careminate\Module\ModuleDependencyResolver;
use Careminate\Module\ModuleIdentifier;

/**
 * Evaluates access to owned services for an explicit module requester.
 *
 * This policy does not itself intercept container resolution.
 *
 * @internal
 */
final readonly class ModuleServiceAccessPolicy
{
    /**
     * @var array<string, array<string, true>>
     */
    private array $dependencies;

    /**
     * @var array<string, string>
     */
    private array $owners;

    /**
     * @var array<string, true>
     */
    private array $exports;

    /**
     * @param list<string> $exportedServices
     */
    public function __construct(
        ModuleServiceDefinitions $services,
        array $exportedServices = [],
    ) {
        $modules = new ModuleDependencyResolver()->resolve(
            $services->modules,
        );

        /** @var array<string, array<string, true>> $dependencies */
        $dependencies = [];

        foreach ($modules as $module) {
            $targets = [];

            foreach ($module->required as $dependency) {
                $targets[$dependency->value] = true;
            }

            foreach ($module->optional as $dependency) {
                $targets[$dependency->value] = true;
            }

            $dependencies[$module->id->value] = $targets;
        }

        /** @var array<string, string> $owners */
        $owners = [];

        foreach ($services->definitions->services as $service) {
            $key = 'entry:' . $service->id;
            $owner = $services->ownerOf($service->id);

            if (
                $owner === null
                || !isset($dependencies[$owner->value])
            ) {
                throw new InvalidModuleBoundaryException(
                    'A service must have an owner in the enabled module set.',
                );
            }

            if (isset($owners[$key])) {
                throw new InvalidModuleBoundaryException(
                    'A service identifier cannot appear more than once.',
                );
            }

            $owners[$key] = $owner->value;
        }

        /** @var array<string, true> $exports */
        $exports = [];

        foreach ($exportedServices as $serviceId) {
            $key = 'entry:' . $serviceId;

            if (!isset($owners[$key])) {
                throw new InvalidModuleBoundaryException(
                    'An exported service must be an owned service in the snapshot.',
                );
            }

            $exports[$key] = true;
        }

        $this->dependencies = $dependencies;
        $this->owners = $owners;
        $this->exports = $exports;
    }

    public function allows(
        ModuleIdentifier $requester,
        string $serviceId,
    ): bool {
        $dependencies = $this->dependencies[$requester->value] ?? null;

        if ($dependencies === null) {
            return false;
        }

        $key = 'entry:' . $serviceId;
        $owner = $this->owners[$key] ?? null;

        if ($owner === null) {
            return false;
        }

        if ($owner === $requester->value) {
            return true;
        }

        return isset($this->exports[$key])
            && isset($dependencies[$owner]);
    }
}
