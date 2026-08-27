<?php

declare(strict_types=1);

namespace Careminate\Contracts\Application;

use Careminate\Application\Bootstrap\BootstrapContext;

interface BootstrapperInterface
{
    public function bootstrap(BootstrapContext $context): void;
}