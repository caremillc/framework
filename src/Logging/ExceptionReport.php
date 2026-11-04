<?php

declare(strict_types=1);

namespace Careminate\Logging;

use Throwable;

final readonly class ExceptionReport
{
    public function __construct(
        public string $correlationId,
        public string $exceptionType,
        public string $severity,
    ) {
    }

    public static function fromThrowable(
        Throwable $exception,
        CorrelationId $correlationId,
    ): self {
        return new self(
            correlationId: $correlationId->value,
            exceptionType: $exception::class,
            severity: LogLevel::Error->value,
        );
    }

    /**
     * @return array<string, string>
     */
    public function toContext(): array
    {
        return [
            'correlation_id' => $this->correlationId,
            'exception_type' => $this->exceptionType,
            'severity' => $this->severity,
        ];
    }
}
