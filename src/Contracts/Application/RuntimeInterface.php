<?php

declare(strict_types=1);

namespace Careminate\Contracts\Application;

use Careminate\Application\Runtime\RuntimeContext;
use Careminate\Application\Runtime\RuntimeResult;
use Careminate\Application\Runtime\RuntimeType;
use Careminate\Contracts\Container\ScopedContainerInterface;
use Throwable;

interface RuntimeInterface
{
    public function type(): RuntimeType;

    public function createContext(
        ScopedContainerInterface $scope,
    ): RuntimeContext;

    public function emit(RuntimeResult $result): int;

    public function terminate(?Throwable $failure): void;
}
