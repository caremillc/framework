<?php

declare(strict_types=1);

namespace Careminate\Application;

use Careminate\Application\Exception\RuntimeExecutionException;
use Careminate\Application\Exception\RuntimeStateException;
use Careminate\Application\Exception\TerminationException;
use Throwable;

/**
 * Owns a single boot, runtime execution, and termination sequence.
 *
 * @internal
 */
final class ApplicationRunner
{
    private readonly ApplicationKernel $kernel;

    private bool $started = false;

    public function __construct(
        private readonly \Psr\Container\ContainerInterface $container,
        BootstrapperInterface ...$bootstrappers,
    ) {
        $this->kernel = new ApplicationKernel(
            $container,
            ...$bootstrappers,
        );
    }

    public function run(RuntimeInterface $runtime): int
    {
        if ($this->started) {
            throw new RuntimeStateException(
                'An application runner can execute only once.',
            );
        }

        $this->started = true;

        $this->kernel->boot();

        try {
            $result = $runtime->run($this->container);
        } catch (Throwable $executionFailure) {
            $terminationFailure = null;

            try {
                $this->kernel->terminate();
            } catch (TerminationException $failure) {
                $terminationFailure = $failure;
            }

            throw new RuntimeExecutionException(
                $executionFailure,
                $terminationFailure,
            );
        }

        $this->kernel->terminate();

        return $result;
    }
}
