<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Foundation;

use Careminate\Container\Container;
use Careminate\Foundation\Application;
use Careminate\Foundation\ServiceProvider;
use Careminate\Logging\CorrelationId;
use Careminate\Logging\ExceptionReporter;
use Careminate\Logging\JsonLogger;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApplicationExceptionReportingTest extends TestCase
{
    public function testSuccessfulBootDoesNotWriteExceptionReport(): void
    {
        $stream = $this->openStream();

        try {
            $application = $this->configuredApplication($stream);

            $application->boot();

            self::assertTrue($application->isBooted());
            self::assertSame('', $this->readStream($stream));
        } finally {
            fclose($stream);
        }
    }

    public function testBootCallbackFailureIsReportedOnce(): void
    {
        $stream = $this->openStream();

        try {
            $application = $this->configuredApplication($stream);

            $original = new RuntimeException(
                'Sensitive boot failure'
            );

            $application->onBoot(
                static function (Application $app) use ($original): void {
                    throw $original;
                }
            );

            try {
                $application->boot();
                self::fail('Expected boot failure.');
            } catch (RuntimeException $exception) {
                self::assertSame($original, $exception);
            }

            self::assertTrue($application->hasFailed());

            $records = $this->readRecords($stream);

            self::assertCount(1, $records);
            self::assertSame('error', $records[0]['level']);

            $context = $records[0]['context'];

            self::assertIsArray($context);

            self::assertSame(
                RuntimeException::class,
                $context['exception_type']
            );

            self::assertStringNotContainsString(
                'Sensitive boot failure',
                $this->readStream($stream)
            );
        } finally {
            fclose($stream);
        }
    }

    public function testProviderRegistrationFailureIsReported(): void
    {
        $stream = $this->openStream();

        try {
            $application = $this->configuredApplication($stream);

            $application->registerProvider(
                new class () implements ServiceProvider {
                    public function register(Container $container): void
                    {
                        throw new RuntimeException(
                            'Provider registration failed'
                        );
                    }

                    public function boot(Container $container): void
                    {
                    }
                }
            );

            try {
                $application->boot();
                self::fail('Expected provider failure.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'Provider registration failed',
                    $exception->getMessage()
                );
            }

            self::assertTrue($application->hasFailed());
            self::assertCount(1, $this->readRecords($stream));
        } finally {
            fclose($stream);
        }
    }

    public function testProviderBootFailureIsReported(): void
    {
        $stream = $this->openStream();

        try {
            $application = $this->configuredApplication($stream);

            $application->registerProvider(
                new class () implements ServiceProvider {
                    public function register(Container $container): void
                    {
                    }

                    public function boot(Container $container): void
                    {
                        throw new RuntimeException(
                            'Provider boot failed'
                        );
                    }
                }
            );

            try {
                $application->boot();
                self::fail('Expected provider boot failure.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'Provider boot failed',
                    $exception->getMessage()
                );
            }

            self::assertTrue($application->hasFailed());
            self::assertCount(1, $this->readRecords($stream));
        } finally {
            fclose($stream);
        }
    }

    public function testReportingFailurePreservesOriginalException(): void
    {
        $stream = $this->openStream();

        $application = $this->configuredApplication($stream);

        fclose($stream);

        $original = new RuntimeException(
            'Original application failure'
        );

        $application->onBoot(
            static function (Application $app) use ($original): void {
                throw $original;
            }
        );

        try {
            $application->boot();
            self::fail('Expected original failure.');
        } catch (RuntimeException $exception) {
            self::assertSame($original, $exception);
        }

        self::assertTrue($application->hasFailed());
    }

    public function testTerminationFailureIsReportedOnce(): void
    {
        $stream = $this->openStream();

        try {
            $application = $this->configuredApplication($stream);

            $events = [];

            $application->onTerminate(
                static function (Application $app) use (&$events): void {
                    $events[] = 'first';
                }
            );

            $application->onTerminate(
                static function (Application $app) use (&$events): void {
                    $events[] = 'second';

                    throw new RuntimeException(
                        'Cleanup failure'
                    );
                }
            );

            $application->boot();

            try {
                $application->terminate();
                self::fail('Expected cleanup failure.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'Cleanup failure',
                    $exception->getMessage()
                );
            }

            self::assertSame(['second', 'first'], $events);
            self::assertTrue($application->isTerminated());
            self::assertCount(1, $this->readRecords($stream));
        } finally {
            fclose($stream);
        }
    }

    public function testCorrelationIdIsReusedInReports(): void
    {
        $stream = $this->openStream();

        try {
            $application = $this->configuredApplication($stream);

            $id = $application->correlationId();

            self::assertInstanceOf(CorrelationId::class, $id);

            $application->onBoot(
                static function (Application $app): void {
                    throw new RuntimeException('Failure');
                }
            );

            try {
                $application->boot();
            } catch (RuntimeException) {
            }

            $records = $this->readRecords($stream);

            self::assertCount(1, $records);

            $context = $records[0]['context'];

            self::assertIsArray($context);

            self::assertSame(
                $id->value,
                $context['correlation_id']
            );
        } finally {
            fclose($stream);
        }
    }

    public function testReporterCannotBeConfiguredAfterBoot(): void
    {
        $stream = $this->openStream();

        try {
            $application = new Application();
            $application->boot();

            $this->expectException(LogicException::class);

            $application->setExceptionReporter(
                new ExceptionReporter(
                    new JsonLogger($stream)
                )
            );
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return resource
     */
    private function openStream()
    {
        $stream = fopen('php://temp', 'w+');

        self::assertIsResource($stream);

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function configuredApplication($stream): Application
    {
        $application = new Application();

        $application->setExceptionReporter(
            new ExceptionReporter(
                new JsonLogger($stream)
            ),
            CorrelationId::fromString(
                str_repeat('a', 32)
            )
        );

        return $application;
    }

    /**
     * @param resource $stream
     */
    private function readStream($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /**
     * @param resource $stream
     *
     * @return list<array<string, mixed>>
     */
    private function readRecords($stream): array
    {
        $output = trim($this->readStream($stream));

        if ($output === '') {
            return [];
        }

        $records = [];

        foreach (explode("\n", $output) as $line) {
            $record = json_decode(
                $line,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            self::assertIsArray($record);

            /** @var array<string, mixed> $record */
            $records[] = $record;
        }

        return $records;
    }
}
