<?php

declare(strict_types=1);

namespace Careminate\Application\Internal;

use Careminate\Application\Exception\InvalidLifecycleTransitionException;

/**
 * Tracks application-wide lifecycle transitions.
 *
 * This component does not execute callbacks or manage request scopes.
 *
 * @internal
 */
final class ApplicationLifecycle
{
    private ApplicationState $state = ApplicationState::Created;

    public function state(): ApplicationState
    {
        return $this->state;
    }

    public function transitionTo(ApplicationState $next): void
    {
        $allowed = match ($this->state) {
            ApplicationState::Created => [
                ApplicationState::Booting,
            ],
            ApplicationState::Booting => [
                ApplicationState::Booted,
                ApplicationState::Failed,
            ],
            ApplicationState::Booted => [
                ApplicationState::Terminating,
            ],
            ApplicationState::Terminating => [
                ApplicationState::Terminated,
                ApplicationState::Failed,
            ],
            ApplicationState::Terminated,
            ApplicationState::Failed => [],
        };

        if (!in_array($next, $allowed, true)) {
            throw new InvalidLifecycleTransitionException(
                sprintf(
                    'The application cannot transition from "%s" to "%s".',
                    $this->state->value,
                    $next->value,
                ),
            );
        }

        $this->state = $next;
    }
}
