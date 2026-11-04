<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Logging;

use Careminate\Logging\JsonLogger;
use Careminate\Logging\LogLevel;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JsonLoggerTest extends TestCase
{
    public function testWritesStructuredJsonRecord(): void
    {
        $stream = fopen('php://temp', 'w+');

        self::assertIsResource($stream);

        $logger = new JsonLogger($stream);

        $logger->log(LogLevel::Info, 'Application started', [
            'environment' => 'testing',
        ]);

        rewind($stream);

        $record = json_decode(
            (string) stream_get_contents($stream),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        fclose($stream);

        // Narrow mixed to array for PHPStan.
        self::assertIsArray($record);

        self::assertSame(
            'info',
            $record['level']
        );

        self::assertSame(
            'Application started',
            $record['message']
        );

        self::assertArrayHasKey('context', $record);

        $context = $record['context'];

        // Narrow the nested context to array.
        self::assertIsArray($context);

        self::assertSame(
            'testing',
            $context['environment']
        );

        self::assertArrayHasKey(
            'timestamp',
            $record
        );
    }


    public function testRedactsSensitiveContext(): void
    {
        $stream = fopen('php://temp', 'w+');

        self::assertIsResource($stream);

        $logger = new JsonLogger($stream);

        $logger->log(LogLevel::Warning, 'Login failed', [
            'password' => 'sensitive-value',
            'nested' => [
                'api_key' => 'another-sensitive-value',
            ],
        ]);

        rewind($stream);

        $output = (string) stream_get_contents($stream);

        fclose($stream);

        self::assertStringNotContainsString(
            'sensitive-value',
            $output
        );
        self::assertStringNotContainsString(
            'another-sensitive-value',
            $output
        );
        self::assertStringContainsString(
            '[REDACTED]',
            $output
        );
    }

    public function testRejectsReadOnlyStream(): void
    {
        $stream = fopen('php://memory', 'r');

        self::assertIsResource($stream);

        try {
            $this->expectException(
                \InvalidArgumentException::class
            );

            new JsonLogger($stream);
        } finally {
            fclose($stream);
        }
    }

    public function testThrowsWhenWritingToClosedStream(): void
    {
        $stream = fopen('php://temp', 'w+');

        self::assertIsResource($stream);

        $logger = new JsonLogger($stream);

        fclose($stream);

        $this->expectException(RuntimeException::class);

        $logger->log(LogLevel::Error, 'Write failure');
    }


    public function testWritesOneJsonObjectPerLine(): void
    {
        $stream = fopen('php://temp', 'w+');

        self::assertIsResource($stream);

        $logger = new JsonLogger($stream);

        $logger->log(LogLevel::Info, 'First');
        $logger->log(LogLevel::Error, 'Second');

        rewind($stream);

        $lines = array_values(array_filter(
            explode(
                "\n",
                (string) stream_get_contents($stream)
            )
        ));

        fclose($stream);

        self::assertCount(2, $lines);

        foreach ($lines as $line) {
            self::assertIsArray(
                json_decode($line, true, 512, JSON_THROW_ON_ERROR)
            );
        }
    }
}
