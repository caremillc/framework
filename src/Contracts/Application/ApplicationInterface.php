<?php

declare(strict_types=1);

namespace Careminate\Contracts\Application;

use Careminate\Application\ApplicationEnvironment;
use Careminate\Application\ApplicationPaths;
use Careminate\Application\ApplicationState;
use Careminate\Contracts\Container\ContainerInterface;
use Throwable;

interface ApplicationInterface
{
    public function bootstrap(): void;

    public function run(RuntimeInterface $runtime): int;

    public function requestTermination(): void;

    public function shouldTerminate(): bool;

    public function terminate(?Throwable $failure = null): void;

    public function environment(): ApplicationEnvironment;

    public function paths(): ApplicationPaths;

    public function state(): ApplicationState;

    public function container(): ContainerInterface;
}
