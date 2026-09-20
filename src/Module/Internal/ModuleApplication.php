<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Application\ApplicationRunner;
use Careminate\Application\RuntimeInterface;

/**
 * Retains module ownership metadata alongside the application runner.
 *
 * @internal
 */
final readonly class ModuleApplication
{
    public function __construct(
        private ApplicationRunner $runner,
        public ModuleServiceDefinitions $services,
    ) {
    }

    public function run(RuntimeInterface $runtime): int
    {
        return $this->runner->run($runtime);
    }
}
