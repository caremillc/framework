<?php

declare(strict_types=1);

namespace Careminate\Contracts\Application;

use Careminate\Application\Termination\TerminationContext;

interface TerminationHookInterface
{
    public function terminate(TerminationContext $context): void;
}