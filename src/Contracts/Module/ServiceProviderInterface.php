<?php

declare(strict_types=1);

namespace Careminate\Contracts\Module;

use Careminate\Module\ModuleContext;

interface ServiceProviderInterface
{
    public function register(ModuleContext $context): void;

    public function boot(ModuleContext $context): void;
}
