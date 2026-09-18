<?php

declare(strict_types=1);

namespace Careminate\Application\Exception;

use Careminate\Exception\FrameworkException;
use Throwable;

/**
 * Preserves an execution failure and any subsequent termination failure.
 */
final class RuntimeExecutionException extends FrameworkException
{
    public function __construct(
        Throwable $executionFailure,
        private readonly ?TerminationException $terminationFailure = null,
    ) {
        parent::__construct(
            message: 'Application runtime execution failed.',
            previous: $executionFailure,
        );
    }

    public function terminationFailure(): ?TerminationException
    {
        return $this->terminationFailure;
    }
}
