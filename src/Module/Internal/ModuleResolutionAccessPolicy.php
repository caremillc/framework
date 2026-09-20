<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Container\Internal\ResolutionAccessPolicyInterface;
use Careminate\Container\Internal\ResolutionExecutionContext;
use Careminate\Module\Exception\InvalidModuleBoundaryException;
use Careminate\Module\ModuleIdentifier;
use Closure;

/**
 * Maps trusted service and lifecycle contexts to module access decisions.
 *
 * @internal
 */
final readonly class ModuleResolutionAccessPolicy implements ResolutionAccessPolicyInterface
{
    private ModuleServiceAccessPolicy $modulePolicy;

    private ResolutionExecutionContext $moduleScope;

    /**
     * @var array<string, true>
     */
    private array $modules;

    /**
     * @var array<string, true>
     */
    private array $exports;

    /**
     * @var array<string, true>
     */
    private array $ownedServices;

    /**
     * @var array<string, true>
     */
    private array $runtimeServices;

    /**
     * @param list<string> $runtimeServices
     */
    public function __construct(
        private ModuleServiceDefinitions $snapshot,
        array $runtimeServices = [],
    ) {
        $this->modulePolicy = new ModuleServiceAccessPolicy(
            $snapshot,
            $snapshot->exportedServices,
        );

        $this->moduleScope = new ResolutionExecutionContext();

        $modules = [];

        foreach ($snapshot->modules as $module) {
            $modules[$module->id->value] = true;
        }

        $owned = [];

        foreach ($snapshot->definitions->services as $service) {
            $owned['entry:' . $service->id] = true;
        }

        $exports = [];

        foreach ($snapshot->exportedServices as $id) {
            $exports['entry:' . $id] = true;
        }

        $runtime = [];

        foreach ($runtimeServices as $id) {
            $key = 'entry:' . $id;

            if ($id === '' || isset($owned[$key])) {
                throw new InvalidModuleBoundaryException(
                    'A runtime service identifier must be nonempty and distinct from module services.',
                );
            }

            $runtime[$key] = true;
        }

        $this->modules = $modules;
        $this->ownedServices = $owned;
        $this->exports = $exports;
        $this->runtimeServices = $runtime;
    }

    public function assertModule(ModuleIdentifier $owner): void
    {
        if (!isset($this->modules[$owner->value])) {
            throw new InvalidModuleBoundaryException(
                'A lifecycle container requires an enabled module owner.',
            );
        }
    }

    /**
     * Called only by trusted module-container composition.
     *
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    public function runAsModule(
        ModuleIdentifier $owner,
        Closure $operation,
    ): mixed {
        $this->assertModule($owner);

        return $this->moduleScope->run($owner->value, $operation);
    }

    public function allows(
        ?string $requesterService,
        string $targetService,
    ): bool {
        $targetKey = 'entry:' . $targetService;

        if ($requesterService === null) {
            $module = $this->moduleScope->requester();

            if ($module !== null) {
                return isset($this->runtimeServices[$targetKey])
                    || $this->modulePolicy->allows(
                        new ModuleIdentifier($module),
                        $targetService,
                    );
            }

            return isset($this->exports[$targetKey])
                || isset($this->runtimeServices[$targetKey]);
        }

        if (!isset($this->ownedServices['entry:' . $requesterService])) {
            return false;
        }

        $owner = $this->snapshot->ownerOf($requesterService);

        if ($owner === null) {
            return false;
        }

        if (isset($this->runtimeServices[$targetKey])) {
            return true;
        }

        return $this->modulePolicy->allows($owner, $targetService);
    }
}
