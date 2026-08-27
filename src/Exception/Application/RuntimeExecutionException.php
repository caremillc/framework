<?php

declare(strict_types=1);

namespace Careminate\Exception\Application;

use Careminate\Application\Runtime\RuntimeType;
use Throwable;

final class RuntimeExecutionException extends ApplicationException
{
    /**
     * @param list<Throwable> $cleanupFailures
     */
    public function __construct(
        string $message,
        private readonly RuntimeType $runtimeType,
        private readonly ?string $contextId,
        private readonly array $cleanupFailures,
        Throwable $previous,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param list<Throwable> $cleanupFailures
     */
    public static function forRuntime(
        RuntimeType $runtime,
        ?string $contextId,
        Throwable $failure,
        array $cleanupFailures,
    ): self {
        $cleanupMessage = $cleanupFailures === []
            ? ''
            : sprintf(
                ' Additionally, %d cleanup operation(s) failed.',
                count($cleanupFailures),
            );

        return new self(
            sprintf(
                'Application runtime "%s" failed%s: %s%s',
                $runtime->value,
                $contextId === null ? '' : sprintf(' for context "%s"', $contextId),
                $failure->getMessage(),
                $cleanupMessage,
            ),
            $runtime,
            $contextId,
            $cleanupFailures,
            $failure,
        );
    }

    public function runtimeType(): RuntimeType
    {
        return $this->runtimeType;
    }

    public function contextId(): ?string
    {
        return $this->contextId;
    }

    /**
     * @return list<Throwable>
     */
    public function cleanupFailures(): array
    {
        return $this->cleanupFailures;
    }
}
