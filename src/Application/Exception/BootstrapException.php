<?php

declare(strict_types=1);

namespace Careminate\Application\Exception;

use Careminate\Exception\FrameworkException;
use Throwable;

/**
 * Reports a bootstrap failure and any subsequent cleanup failures.
 */
final class BootstrapException extends FrameworkException
{
    /**
     * @var list<Throwable>
     */
    private readonly array $cleanupFailures;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        Throwable ...$cleanupFailures,
    ) {
        parent::__construct($message, $code, $previous);

        $this->cleanupFailures = array_values($cleanupFailures);
    }

    /**
     * Failures in cleanup execution order.
     *
     * @return list<Throwable>
     */
    public function cleanupFailures(): array
    {
        return $this->cleanupFailures;
    }
}
