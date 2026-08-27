<?php

declare(strict_types=1);

namespace Careminate\Module;

use Careminate\Application\Bootstrap\BootstrapContext;
use Careminate\Contracts\Application\BootstrapperInterface;

final readonly class ModuleBootstrapper implements BootstrapperInterface
{
    public function __construct(private ModuleManager $modules)
    {
    }

    public function bootstrap(BootstrapContext $context): void
    {
        $this->modules->bootstrap($context);
    }
}