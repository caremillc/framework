<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Foundation;

use Flint\Container\Container;
use Flint\Foundation\Application;
use Flint\Foundation\ServiceProvider;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApplicationTerminationTest extends TestCase
{
    public function testCleanupRunsInReverseOrderExactlyOnce(): void
    {
        $application = new Application(__DIR__);
        $events = [];

        $application->onTerminate(
            static function () use (&$events): void {
                $events[] = 'first';
            },
        );

        $application->onTerminate(
            static function () use (&$events): void {
                $events[] = 'second';
            },
        );

        $application->boot();

        self::assertFalse($application->isTerminated());

        $application->terminate();
        $application->terminate();

        self::assertSame(['second', 'first'], $events);
        self::assertTrue($application->isTerminated());
        self::assertFalse($application->isBooted());
    }

    public function testCleanupContinuesAfterFailures(): void
    {
        $application = new Application(__DIR__);
        $firstFailure = new RuntimeException('First cleanup failure.');
        $laterFailure = new RuntimeException('Later cleanup failure.');
        $events = [];

        $application->onTerminate(
            static function () use (&$events): void {
                $events[] = 'last';
            },
        );

        $application->onTerminate(
            static function () use (&$events, $laterFailure): void {
                $events[] = 'middle';

                throw $laterFailure;
            },
        );

        $application->onTerminate(
            static function () use (&$events, $firstFailure): void {
                $events[] = 'first';

                throw $firstFailure;
            },
        );

        try {
            $application->terminate();

            self::fail('Termination must report the first cleanup failure.');
        } catch (RuntimeException $exception) {
            self::assertSame($firstFailure, $exception);
        }

        self::assertSame(['first', 'middle', 'last'], $events);
        self::assertTrue($application->isTerminated());

        $application->terminate();

        self::assertSame(['first', 'middle', 'last'], $events);
    }

    public function testCleanupRunsAfterRegistrationFailure(): void
    {
        $application = new Application(__DIR__);
        $failure = new RuntimeException('Registration failed.');
        $cleaned = false;

        $application->onTerminate(
            static function () use (&$cleaned): void {
                $cleaned = true;
            },
        );

        $provider = new class ($failure) implements ServiceProvider {
            public function __construct(
                private readonly RuntimeException $failure,
            ) {
            }

            public function register(Container $container): void
            {
                throw $this->failure;
            }
        };

        try {
            $application->register($provider);

            self::fail('Registration must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        $application->terminate();

        self::assertTrue($cleaned);
        self::assertTrue($application->isTerminated());
    }

    public function testCleanupRegistrationAfterTerminationIsRejected(): void
    {
        $application = new Application(__DIR__);
        $application->terminate();

        $this->expectException(LogicException::class);

        $application->onTerminate(
            static function (): void {
            },
        );
    }

    public function testTerminatedApplicationCannotBoot(): void
    {
        $application = new Application(__DIR__);
        $application->terminate();

        $this->expectException(LogicException::class);

        $application->boot();
    }
}
