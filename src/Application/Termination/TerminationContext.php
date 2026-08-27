<?php

declare(strict_types=1);

namespace Careminate\Application\Termination;

use Careminate\Application\ApplicationEnvironment;
use Careminate\Application\ApplicationPaths;
use Careminate\Application\ApplicationState;
use Throwable;

final readonly class TerminationContext
{
    public function __construct(
        public ApplicationEnvironment $environment,
        public ApplicationPaths $paths,
        public ApplicationState $stateBeforeTermination,
        public bool $requested,
        public ?Throwable $failure,
    ) {
    }
}