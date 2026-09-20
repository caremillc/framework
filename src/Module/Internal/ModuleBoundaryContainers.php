<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Container\Container;
use Careminate\Module\ModuleIdentifier;

/**
 * Holds an enforced application container and its lifecycle-view factory.
 *
 * Keep this composition object outside module-facing runtime objects.
 *
 * @internal
 */
final readonly class ModuleBoundaryContainers
{
    public function __construct(
        public Container $application,
        private ModuleResolutionAccessPolicy $policy,
    ) {
    }

    public function forModule(ModuleIdentifier $owner): ModuleScopedContainer
    {
        return new ModuleScopedContainer(
            $this->application,
            $this->policy,
            $owner,
        );
    }
}
