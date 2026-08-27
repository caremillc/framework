<?php

declare(strict_types=1);

namespace Careminate\Contracts\Module;

interface ModuleDiscoveryInterface
{
    /**
     * @return iterable<class-string<ModuleInterface>>
     */
    public function discover(): iterable;
}
