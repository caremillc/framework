<?php

declare(strict_types=1);

namespace Careminate\Application;

use Careminate\Application\Exception\BootstrapException;
use Careminate\Application\Exception\TerminationException;
use Careminate\Application\Internal\ApplicationLifecycle;
use Careminate\Application\Internal\ApplicationState;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Coordinates application bootstrap and termination.
 *
 * @internal
 */
final class ApplicationKernel
{
    private readonly ApplicationLifecycle $lifecycle;

    /**
     * @var list<BootstrapperInterface>
     */
    private readonly array $bootstrappers;

    /**
     * @var list<TerminableBootstrapperInterface>
     */
    private array $completedBootstrappers = [];

    public function __construct(
        private readonly ContainerInterface $container,
        BootstrapperInterface ...$bootstrappers,
    ) {
        $this->lifecycle = new ApplicationLifecycle();
        $this->bootstrappers = array_values($bootstrappers);
    }

    public function state(): ApplicationState
    {
        return $this->lifecycle->state();
    }

    public function boot(): void
    {
        if ($this->lifecycle->state() === ApplicationState::Booted) {
            return;
        }

        $this->lifecycle->transitionTo(ApplicationState::Booting);

        try {
            foreach ($this->bootstrappers as $bootstrapper) {
                $bootstrapper->bootstrap($this->container);

                if ($bootstrapper instanceof TerminableBootstrapperInterface) {
                    $this->completedBootstrappers[] = $bootstrapper;
                }
            }
        } catch (Throwable $previous) {
            $this->lifecycle->transitionTo(ApplicationState::Failed);

            $cleanupFailures = $this->cleanup();

            throw new BootstrapException(
                'Application boot failed.',
                0,
                $previous,
                ...$cleanupFailures,
            );
        }

        $this->lifecycle->transitionTo(ApplicationState::Booted);
    }

    public function terminate(): void
    {
        $state = $this->lifecycle->state();

        if (
            $state === ApplicationState::Terminated
            || $state === ApplicationState::Failed
        ) {
            return;
        }

        $this->lifecycle->transitionTo(ApplicationState::Terminating);

        $failures = $this->cleanup();

        if ($failures !== []) {
            $this->lifecycle->transitionTo(ApplicationState::Failed);

            throw new TerminationException(
                $failures[0],
                ...array_slice($failures, 1),
            );
        }

        $this->lifecycle->transitionTo(ApplicationState::Terminated);
    }

    /**
     * @return list<Throwable>
     */
    private function cleanup(): array
    {
        $pending = array_reverse($this->completedBootstrappers);

        $this->completedBootstrappers = [];

        $failures = [];

        foreach ($pending as $bootstrapper) {
            try {
                $bootstrapper->terminate($this->container);
            } catch (Throwable $failure) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }
}
