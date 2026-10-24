<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Logging;

use Careminate\Logging\JsonLogger;
use Careminate\Logging\LogLevel;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JsonLoggerHardeningTest extends TestCase
{
    public function testBearerTokenIsRedactedFromMessage(): void
    {
        $output = $this->capture(
            'Authorization: Bearer abc123.SECRET'
        );

        self::assertStringNotContainsString(
            'abc123.SECRET',
            $output
        );

        self::assertStringContainsString(
            '[REDACTED]',
            $output
        );
    }

    public function testCredentialAssignmentIsRedacted(): void
    {
        $output = $this->capture(
            'Connection failed: password=supersecret'
        );

        self::assertStringNotContainsString(
            'supersecret',
            $output
        );
    }

    public function testNestedSensitiveKeysAreRedacted(): void
    {
        $output = $this->capture('Example', [
            'request' => [
                'headers' => [
                    'authorization' => 'Bearer sensitive',
                ],
            ],
        ]);

        self::assertStringNotContainsString(
            'sensitive',
            $output
        );
    }

    public function testExceptionMessageIsExcluded(): void
    {
        $output = $this->capture('Failure', [
            'exception' => new RuntimeException(
                'PRIVATE_EXCEPTION_MESSAGE'
            ),
        ]);

        self::assertStringNotContainsString(
            'PRIVATE_EXCEPTION_MESSAGE',
            $output
        );

        self::assertStringContainsString(
            RuntimeException::class,
            $output
        );
    }

    public function testNonFiniteFloatIsNormalized(): void
    {
        $output = $this->capture('Metrics', [
            'value' => INF,
        ]);

        self::assertStringContainsString(
            '[NON_FINITE]',
            $output
        );
    }

    public function testDeeplyNestedContextIsBounded(): void
    {
        $context = ['value' => 'safe'];

        for ($index = 0; $index < 12; ++$index) {
            $context = ['nested' => $context];
        }

        $output = $this->capture('Deep context', $context);

        self::assertStringContainsString(
            '[MAX_DEPTH]',
            $output
        );
    }

    public function testLargeContextIsTruncated(): void
    {
        $context = [];

        for ($index = 0; $index < 150; ++$index) {
            $context['key_' . $index] = $index;
        }

        $output = $this->capture('Large context', $context);

        self::assertStringContainsString(
            '[MAX_ITEMS]',
            $output
        );
    }

    public function testLongMessageIsTruncated(): void
    {
        $output = $this->capture(
            str_repeat('A', 5000)
        );

        $record = json_decode(
            $output,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertIsArray($record);
        self::assertIsString($record['message']);
        self::assertSame(
            4096,
            strlen($record['message'])
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function capture(
        string $message,
        array $context = [],
    ): string {
        $stream = fopen('php://temp', 'w+');

        self::assertIsResource($stream);

        try {
            $logger = new JsonLogger($stream);

            $logger->log(
                LogLevel::Info,
                $message,
                $context
            );

            rewind($stream);

            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }
}
