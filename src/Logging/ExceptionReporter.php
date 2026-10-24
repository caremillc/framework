<?php

declare(strict_types=1);

namespace Careminate\Logging;

use Throwable;

final readonly class ExceptionReporter
{
    public function __construct(
        private JsonLogger $logger,
    ) {
    }

    public function report(
        Throwable $exception,
        ?CorrelationId $correlationId = null,
    ): ExceptionReport {
        $correlationId ??= CorrelationId::generate();

        $report = ExceptionReport::fromThrowable(
            $exception,
            $correlationId,
        );

        $this->logger->log(
            LogLevel::Error,
            'Unhandled exception',
            $report->toContext(),
        );

        return $report;
    }
}
