<?php

declare(strict_types=1);

namespace Careminate\Application\Termination;

use Careminate\Contracts\Application\TerminationHookInterface;
use Careminate\Exception\Application\ApplicationException;
use Careminate\Exception\Application\TerminationException;
use Throwable;

final class TerminationManager
{
    /**
     * @var list<array{
     *     hook: TerminationHookInterface,
     *     priority: int,
     *     order: int
     * }>
     */
    private array $entries = [];

    /**
     * @var array<class-string, true>
     */
    private array $registered = [];

    private int $nextOrder = 0;

    public function add(
        TerminationHookInterface $hook,
        int $priority = 0,
    ): void {
        $class = $hook::class;

        if (isset($this->registered[$class])) {
            throw new ApplicationException(
                sprintf(
                    'Termination hook "%s" is already registered.',
                    $class,
                ),
            );
        }

        $this->registered[$class] = true;

        $this->entries[] = [
            'hook' => $hook,
            'priority' => $priority,
            'order' => $this->nextOrder++,
        ];
    }

    public function terminate(TerminationContext $context): void
    {
        $entries = $this->entries;

        usort(
            $entries,
            static fn (array $left, array $right): int =>
                [$right['priority'], $right['order']]
                <=> [$left['priority'], $left['order']],
        );

        $failures = [];

        foreach ($entries as $entry) {
            try {
                $entry['hook']->terminate($context);
            } catch (Throwable $exception) {
                $failures[] = $exception;
            }
        }

        if ($failures !== []) {
            throw TerminationException::fromFailures($failures);
        }
    }
}
