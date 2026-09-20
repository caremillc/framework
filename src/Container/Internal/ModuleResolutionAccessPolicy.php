<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Container\Internal\ResolutionAccessPolicyInterface;
use Careminate\Module\Exception\InvalidModuleBoundaryException;

/**
 * Maps trusted requesting service identities to module access decisions.
 *
 * @internal
 */
final readonly class ModuleResolutionAccessPolicy implements ResolutionAccessPolicyInterface
{
    private ModuleServiceAccessPolicy $modulePolicy;

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

        $this->ownedServices = $owned;
        $this->exports = $exports;
        $this->runtimeServices = $runtime;
    }

    public function allows(
        ?string $requesterService,
        string $targetService,
    ): bool {
        $targetKey = 'entry:' . $targetService;

        if ($requesterService === null) {
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
