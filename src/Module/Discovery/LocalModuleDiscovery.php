<?php

declare(strict_types=1);

namespace Careminate\Module\Discovery;

use Careminate\Contracts\Module\ModuleDiscoveryInterface;
use Careminate\Contracts\Module\ModuleInterface;

final readonly class LocalModuleDiscovery implements ModuleDiscoveryInterface
{
    /**
     * @param list<class-string<ModuleInterface>> $modules
     */
    public function __construct(private array $modules)
    {
    }

    public function discover(): iterable
    {
        return $this->modules;
    }
}
