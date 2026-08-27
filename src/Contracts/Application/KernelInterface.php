<?php

declare(strict_types=1);

namespace Careminate\Contracts\Application;

use Careminate\Application\Runtime\RuntimeContext;
use Careminate\Application\Runtime\RuntimeResult;
use Throwable;

interface KernelInterface
{
    public function handle(RuntimeContext $context): RuntimeResult;

    public function terminate(
        RuntimeContext $context,
        ?RuntimeResult $result,
        ?Throwable $failure,
    ): void;
}