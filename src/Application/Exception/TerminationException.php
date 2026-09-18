<?php

declare(strict_types=1);

namespace Careminate\Application\Exception;

use Careminate\Exception\FrameworkException;
use Throwable;

/**
 * Reports all failures encountered while terminating an application.
 */
final class TerminationException extends FrameworkException
{
    /**
     * @var non-empty-list<Throwable>
     */
    private readonly array $failures;

    public function __construct(
        Throwable $firstFailure,
        Throwable ...$additionalFailures,
    ) {
        parent::__construct(
            message: 'Application termination failed.',
            previous: $firstFailure,
        );

        $this->failures = [
            $firstFailure,
            ...array_values($additionalFailures),
        ];
    }

    /**
     * Failures in cleanup execution order.
     *
     * @return non-empty-list<Throwable>
     */
    public function failures(): array
    {
        return $this->failures;
    }
}
