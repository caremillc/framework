<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Module\ModuleIdentifier;
use Psr\Container\ContainerInterface;

/**
 * Container capability bound to one enabled module.
 *
 * @internal
 */
final readonly class ModuleScopedContainer implements ContainerInterface
{
    public function __construct(
        private ContainerInterface $container,
        private ModuleResolutionAccessPolicy $policy,
        private ModuleIdentifier $owner,
    ) {
        $policy->assertModule($owner);
    }

    public function get(string $id): mixed
    {
        return $this->policy->runAsModule(
            $this->owner,
            fn (): mixed => $this->container->get($id),
        );
    }

    public function has(string $id): bool
    {
        return $this->policy->runAsModule(
            $this->owner,
            fn (): bool => $this->policy->allows(null, $id)
                && $this->container->has($id),
        );
    }
}
