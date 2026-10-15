<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Foundation;

use Flint\Container\Container;
use Flint\Foundation\Application;
use Flint\Foundation\ApplicationRunException;
use Flint\Foundation\BootableServiceProvider;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApplicationRunTest extends TestCase
{
    public function testSuccessfulExecutionReturnsItsResultAfterCleanup(): void
    {
        $application = new Application(__DIR__);
        $events = [];
        $expected = new \stdClass();

        $application->onTerminate(
            static function () use (&$events): void {
                $events[] = 'cleanup';
            },
        );

        $actual = $application->run(
            static function (Application $running) use (
                &$events,
                $expected,
            ): object {
                self::assertTrue($running->isBooted());
                $events[] = 'execute';

                return $expected;
            },
        );

        self::assertSame($expected, $actual);
        self::assertSame(['execute', 'cleanup'], $events);
        self::assertTrue($application->isTerminated());
    }

    public function testExecutionFailureRetainsIdentityAndRunsCleanup(): void
    {
        $application = new Application(__DIR__);
        $failure = new RuntimeException('Execution failed.');
        $cleaned = false;

        $application->onTerminate(
            static function () use (&$cleaned): void {
                $cleaned = true;
            },
        );

        try {
            $application->run(
                static function (Application $running) use ($failure): never {
                    throw $failure;
                },
            );

            // self::fail('Execution must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertTrue($cleaned);
        self::assertTrue($application->isTerminated());
    }

    public function testCleanupFailureAfterSuccessRetainsIdentity(): void
    {
        $application = new Application(__DIR__);
        $failure = new RuntimeException('Cleanup failed.');

        $application->onTerminate(
            static function () use ($failure): void {
                throw $failure;
            },
        );

        try {
            $application->run(
                static fn (Application $running): string => 'result',
            );

            // self::fail('Cleanup failure must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertTrue($application->isTerminated());
    }

    public function testCombinedFailureRetainsBothExceptions(): void
    {
        $application = new Application(__DIR__);
        $executionFailure = new RuntimeException('Execution failed.');
        $terminationFailure = new RuntimeException('Cleanup failed.');

        $application->onTerminate(
            static function () use ($terminationFailure): void {
                throw $terminationFailure;
            },
        );

        try {
            $application->run(
                static function (Application $running) use (
                    $executionFailure,
                ): never {
                    throw $executionFailure;
                },
            );

            // self::fail('Both failures must be reported.');
        } catch (ApplicationRunException $exception) {
            self::assertSame(
                $executionFailure,
                $exception->executionFailure,
            );
            self::assertSame(
                $executionFailure,
                $exception->getPrevious(),
            );
            self::assertSame(
                $terminationFailure,
                $exception->terminationFailure,
            );
        }

        self::assertTrue($application->isTerminated());
    }

    public function testBootFailureSkipsExecutionAndRunsCleanup(): void
    {
        $application = new Application(__DIR__);
        $failure = new RuntimeException('Boot failed.');
        $cleaned = false;

        $application->onTerminate(
            static function () use (&$cleaned): void {
                $cleaned = true;
            },
        );

        $application->register(
            new class ($failure) implements BootableServiceProvider {
                public function __construct(
                    private readonly RuntimeException $failure,
                ) {
                }

                public function register(Container $container): void
                {
                }

                public function boot(Container $container): void
                {
                    throw $this->failure;
                }
            },
        );

        try {
            $application->run(
                static function (Application $running): void {
                    self::fail('Execution must not follow failed boot.');
                },
            );

            // self::fail('Boot failure must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertTrue($cleaned);
        self::assertTrue($application->isTerminated());
    }

    public function testNestedExecutionCannotTerminateTheOuterOperation(): void
    {
        $application = new Application(__DIR__);
        $cleanups = 0;

        $application->onTerminate(
            static function () use (&$cleanups): void {
                ++$cleanups;
            },
        );

        $application->run(
            static function (Application $running) use (&$cleanups): void {
                try {
                    $running->run(
                        static function (Application $nested): void {
                            self::fail('Nested execution must not run.');
                        },
                    );

                    // self::fail('Nested execution must be rejected.');
                } catch (LogicException) {
                    self::assertTrue($running->isBooted());
                    self::assertFalse($running->isTerminated());
                    self::assertSame(0, $cleanups);
                }
            },
        );

        self::assertSame(1, $cleanups);
        self::assertTrue($application->isTerminated());
    }
}
