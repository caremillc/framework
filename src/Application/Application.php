<?php

declare(strict_types=1);

namespace Careminate\Application;

use Careminate\Application\Bootstrap\BootstrapContext;
use Careminate\Application\Bootstrap\BootstrapSequence;
use Careminate\Application\Kernel\KernelRegistry;
use Careminate\Application\Runtime\RuntimeContext;
use Careminate\Application\Runtime\RuntimeResult;
use Careminate\Application\Termination\TerminationContext;
use Careminate\Application\Termination\TerminationManager;
use Careminate\Contracts\Application\ApplicationInterface;
use Careminate\Contracts\Application\KernelInterface;
use Careminate\Contracts\Application\RuntimeInterface;
use Careminate\Contracts\Container\ContainerInterface;
use Careminate\Contracts\Container\ContainerRegistryInterface;
use Careminate\Contracts\Container\ScopedContainerInterface;
use Careminate\Exception\Application\BootstrapException;
use Careminate\Exception\Application\LifecycleException;
use Careminate\Exception\Application\RuntimeExecutionException;
use Careminate\Exception\Application\TerminationException;
use Throwable;

final class Application implements ApplicationInterface
{
    private bool $terminationRequested = false;

    private ?float $bootstrappedAt = null;

    public function __construct(
        private readonly ContainerInterface&ContainerRegistryInterface $serviceContainer,
        private readonly ApplicationEnvironment $applicationEnvironment,
        private readonly ApplicationPaths $applicationPaths,
        private readonly ApplicationStateMachine $stateMachine,
        private readonly BootstrapSequence $bootstrapSequence,
        private readonly KernelRegistry $kernelRegistry,
        private readonly TerminationManager $terminationManager,
    ) {
    }

    public function bootstrap(): void
    {
        if ($this->state() === ApplicationState::Bootstrapped) {
            return;
        }

        if ($this->state() !== ApplicationState::Created) {
            throw LifecycleException::expected(
                ApplicationState::Created,
                $this->state(),
            );
        }

        $this->stateMachine->transition(ApplicationState::Bootstrapping);

        try {
            $this->bootstrapSequence->execute(
                new BootstrapContext(
                    $this->serviceContainer,
                    $this->applicationEnvironment,
                    $this->applicationPaths,
                    $this->kernelRegistry,
                ),
            );

            $this->kernelRegistry->freeze();
            $this->bootstrappedAt = microtime(true);

            $this->stateMachine->transition(ApplicationState::Bootstrapped);
        } catch (Throwable $exception) {
            $this->stateMachine->fail();

            if ($exception instanceof BootstrapException) {
                throw $exception;
            }

            throw BootstrapException::forBootstrapper(
                self::class,
                $exception,
            );
        }
    }

    public function run(RuntimeInterface $runtime): int
    {
        $this->bootstrap();

        if ($this->state() !== ApplicationState::Bootstrapped) {
            throw LifecycleException::expected(
                ApplicationState::Bootstrapped,
                $this->state(),
            );
        }

        $this->stateMachine->transition(ApplicationState::Running);

        $scope = null;
        $context = null;
        $kernel = null;
        $result = null;
        $exitCode = 1;
        $failure = null;
        $cleanupFailures = [];

        try {
            $scope = $this->serviceContainer->createScope(
                sprintf(
                    'runtime:%s:%s',
                    $runtime->type()->value,
                    bin2hex(random_bytes(8)),
                ),
            );

            $context = $runtime->createContext($scope);

            $kernel = $this->kernelRegistry->resolve(
                $runtime->type(),
                $scope,
            );

            $result = $kernel->handle($context);
            $exitCode = $runtime->emit($result);
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $this->cleanupRuntime(
            $runtime,
            $kernel,
            $context,
            $result,
            $scope,
            $failure,
            $cleanupFailures,
        );

        if ($failure !== null || $cleanupFailures !== []) {
            $this->stateMachine->fail();

            if ($failure !== null) {
                throw RuntimeExecutionException::forRuntime(
                    $runtime->type(),
                    $context?->id,
                    $failure,
                    $cleanupFailures,
                );
            }

            /** @var non-empty-list<Throwable> $cleanupFailures */
            throw TerminationException::fromFailures($cleanupFailures);
        }

        $this->stateMachine->transition(ApplicationState::Bootstrapped);

        if ($this->terminationRequested) {
            $this->terminate();
        }

        return $exitCode;
    }

    public function requestTermination(): void
    {
        $this->terminationRequested = true;
    }

    public function shouldTerminate(): bool
    {
        return $this->terminationRequested;
    }

    public function terminate(?Throwable $failure = null): void
    {
        if ($this->state() === ApplicationState::Terminated) {
            return;
        }

        if ($this->state() === ApplicationState::Running) {
            $this->requestTermination();

            return;
        }

        $stateBeforeTermination = $this->state();

        $this->stateMachine->transition(ApplicationState::Terminating);

        $terminationFailure = null;

        try {
            $this->terminationManager->terminate(
                new TerminationContext(
                    $this->applicationEnvironment,
                    $this->applicationPaths,
                    $stateBeforeTermination,
                    $this->terminationRequested,
                    $failure,
                ),
            );
        } catch (Throwable $exception) {
            $terminationFailure = $exception;
        } finally {
            $this->stateMachine->transition(ApplicationState::Terminated);
        }

        if ($terminationFailure !== null) {
            throw $terminationFailure;
        }
    }

    public function environment(): ApplicationEnvironment
    {
        return $this->applicationEnvironment;
    }

    public function paths(): ApplicationPaths
    {
        return $this->applicationPaths;
    }

    public function state(): ApplicationState
    {
        return $this->stateMachine->state();
    }

    public function container(): ContainerInterface
    {
        return $this->serviceContainer;
    }

    public function bootstrappedAt(): ?float
    {
        return $this->bootstrappedAt;
    }

    /**
     * @param list<Throwable> $cleanupFailures
     */
    private function cleanupRuntime(
        RuntimeInterface $runtime,
        ?KernelInterface $kernel,
        ?RuntimeContext $context,
        ?RuntimeResult $result,
        ?ScopedContainerInterface $scope,
        ?Throwable $failure,
        array &$cleanupFailures,
    ): void {
        if ($kernel !== null && $context !== null) {
            try {
                $kernel->terminate($context, $result, $failure);
            } catch (Throwable $exception) {
                $cleanupFailures[] = $exception;
            }
        }

        try {
            $runtime->terminate($failure);
        } catch (Throwable $exception) {
            $cleanupFailures[] = $exception;
        }

        if ($scope !== null && !$scope->isClosed()) {
            try {
                $scope->close();
            } catch (Throwable $exception) {
                $cleanupFailures[] = $exception;
            }
        }
    }
}
