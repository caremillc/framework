<?php

declare(strict_types=1);

namespace Careminate\Application;

use Careminate\Exception\Application\LifecycleException;

final class ApplicationStateMachine
{
    private ApplicationState $state = ApplicationState::Created;

    /**
     * @var list<ApplicationState>
     */
    private array $history;

    public function __construct()
    {
        $this->history = [ApplicationState::Created];
    }

    public function state(): ApplicationState
    {
        return $this->state;
    }

    public function transition(ApplicationState $next): void
    {
        if (!$this->canTransitionTo($next)) {
            throw LifecycleException::invalidTransition(
                $this->state,
                $next,
            );
        }

        $this->state = $next;
        $this->history[] = $next;
    }

    public function fail(): void
    {
        if ($this->state === ApplicationState::Failed) {
            return;
        }

        $this->transition(ApplicationState::Failed);
    }

    public function canTransitionTo(ApplicationState $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * @return list<ApplicationState>
     */
    public function history(): array
    {
        return $this->history;
    }

    /**
     * @return list<ApplicationState>
     */
    private function allowedTransitions(): array
    {
        return match ($this->state) {
            ApplicationState::Created => [
                ApplicationState::Bootstrapping,
                ApplicationState::Terminating,
                ApplicationState::Failed,
            ],
            ApplicationState::Bootstrapping => [
                ApplicationState::Bootstrapped,
                ApplicationState::Failed,
            ],
            ApplicationState::Bootstrapped => [
                ApplicationState::Running,
                ApplicationState::Terminating,
                ApplicationState::Failed,
            ],
            ApplicationState::Running => [
                ApplicationState::Bootstrapped,
                ApplicationState::Failed,
            ],
            ApplicationState::Failed => [
                ApplicationState::Terminating,
            ],
            ApplicationState::Terminating => [
                ApplicationState::Terminated,
            ],
            ApplicationState::Terminated => [],
        };
    }
}
