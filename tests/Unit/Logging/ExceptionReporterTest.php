<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Logging;

use Careminate\Logging\CorrelationId;
use Careminate\Logging\ExceptionReport;
use Careminate\Logging\ExceptionReporter;
use Careminate\Logging\JsonLogger;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExceptionReporterTest extends TestCase
{
    public function testGeneratedCorrelationIdIsValid(): void
    {
        $id = CorrelationId::generate();

        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{32}\z/D',
            $id->value
        );
    }

    public function testGeneratedCorrelationIdsAreDistinct(): void
    {
        self::assertNotSame(
            CorrelationId::generate()->value,
            CorrelationId::generate()->value
        );
    }

    public function testCorrelationIdAcceptsValidString(): void
    {
        $value = str_repeat('a', 32);

        $id = CorrelationId::fromString($value);

        self::assertSame($value, (string) $id);
    }

    public function testCorrelationIdRejectsInvalidString(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        CorrelationId::fromString('invalid-id');
    }

    public function testExceptionReportExcludesSensitiveDetails(): void
    {
        $exception = new RuntimeException(
            'Sensitive database password'
        );

        $id = CorrelationId::fromString(
            str_repeat('b', 32)
        );

        $report = ExceptionReport::fromThrowable(
            $exception,
            $id
        );

        $context = $report->toContext();

        self::assertSame(
            $id->value,
            $context['correlation_id']
        );

        self::assertSame(
            RuntimeException::class,
            $context['exception_type']
        );

        self::assertSame(
            'error',
            $context['severity']
        );

        $serialized = json_encode(
            $context,
            JSON_THROW_ON_ERROR
        );

        self::assertStringNotContainsString(
            'Sensitive database password',
            $serialized
        );

        self::assertArrayNotHasKey('trace', $context);
        self::assertArrayNotHasKey('file', $context);
        self::assertArrayNotHasKey('message', $context);
    }

    public function testReporterWritesSafeStructuredRecord(): void
    {
        $stream = fopen('php://temp', 'w+');

        self::assertIsResource($stream);

        try {
            $reporter = new ExceptionReporter(
                new JsonLogger($stream)
            );

            $id = CorrelationId::fromString(
                str_repeat('c', 32)
            );

            $report = $reporter->report(
                new RuntimeException('SECRET-CREDENTIAL'),
                $id
            );

            rewind($stream);

            $output = (string) stream_get_contents($stream);

            self::assertSame(
                $id->value,
                $report->correlationId
            );

            self::assertStringContainsString(
                $id->value,
                $output
            );

            self::assertStringNotContainsString(
                'SECRET-CREDENTIAL',
                $output
            );

            $record = json_decode(
                $output,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            self::assertIsArray($record);
            self::assertSame('error', $record['level']);
            self::assertSame(
                'Unhandled exception',
                $record['message']
            );

            self::assertArrayHasKey('context', $record);

            $context = $record['context'];

            self::assertIsArray($context);
            self::assertSame(
                RuntimeException::class,
                $context['exception_type']
            );
        } finally {
            fclose($stream);
        }
    }

    public function testReporterGeneratesCorrelationIdWhenMissing(): void
    {
        $stream = fopen('php://temp', 'w+');

        self::assertIsResource($stream);

        try {
            $reporter = new ExceptionReporter(
                new JsonLogger($stream)
            );

            $report = $reporter->report(
                new RuntimeException('Example')
            );

            self::assertMatchesRegularExpression(
                '/\A[a-f0-9]{32}\z/D',
                $report->correlationId
            );
        } finally {
            fclose($stream);
        }
    }

    public function testReporterPropagatesLoggingFailure(): void
    {
        $stream = fopen('php://temp', 'w+');

        self::assertIsResource($stream);

        $reporter = new ExceptionReporter(
            new JsonLogger($stream)
        );

        fclose($stream);

        $this->expectException(RuntimeException::class);

        $reporter->report(
            new RuntimeException('Original exception')
        );
    }
}
