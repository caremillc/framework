<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Foundation;

use Careminate\Container\Container;
use Careminate\Foundation\Application;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApplicationTest extends TestCase
{
    public function testApplicationOwnsContainer(): void
    {
        $application = new Application();

        self::assertSame(
            $application->container(),
            $application->container()
        );
    }

    public function testInjectedContainerIsPreserved(): void
    {
        $container = new Container();
        $application = new Application($container);

        self::assertSame($container, $application->container());
    }

    public function testBootTransitionsApplicationToBooted(): void
    {
        $application = new Application();

        self::assertFalse($application->isBooted());

        $application->boot();

        self::assertTrue($application->isBooted());
    }

    public function testBootCallbacksExecuteInOrder(): void
    {
        $application = new Application();
        $events = [];

        $application->onBoot(
            static function (Application $app) use (&$events): void {
                $events[] = 'first';
            }
        );

        $application->onBoot(
            static function (Application $app) use (&$events): void {
                $events[] = 'second';
            }
        );

        $application->boot();

        self::assertSame(['first', 'second'], $events);
    }

    public function testBootIsIdempotent(): void
    {
        $application = new Application();
        $calls = 0;

        $application->onBoot(
            static function (Application $app) use (&$calls): void {
                ++$calls;
            }
        );

        $application->boot();
        $application->boot();

        self::assertSame(1, $calls);
    }

    public function testRecursiveBootIsRejected(): void
    {
        $application = new Application();

        $application->onBoot(
            static function (Application $app): void {
                $app->boot();
            }
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Cannot boot application in state "booting".'
        );

        $application->boot();
    }

    public function testFailedBootPreventsRetry(): void
    {
        $application = new Application();

        $application->onBoot(
            static function (Application $app): void {
                throw new RuntimeException('Boot failed.');
            }
        );

        try {
            $application->boot();
            self::fail('Expected boot failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Boot failed.', $exception->getMessage());
        }

        self::assertTrue($application->hasFailed());

        $this->expectException(LogicException::class);

        $application->boot();
    }

    public function testBootCallbacksCannotBeAddedAfterBoot(): void
    {
        $application = new Application();
        $application->boot();

        $this->expectException(LogicException::class);

        $application->onBoot(
            static function (Application $app): void {
            }
        );
    }

    public function testTerminationRunsCleanupInReverseOrder(): void
    {
        $application = new Application();
        $events = [];

        $application->onTerminate(
            static function (Application $app) use (&$events): void {
                $events[] = 'first';
            }
        );

        $application->onTerminate(
            static function (Application $app) use (&$events): void {
                $events[] = 'second';
            }
        );

        $application->boot();
        $application->terminate();

        self::assertSame(['second', 'first'], $events);
        self::assertTrue($application->isTerminated());
    }

    public function testTerminationIsIdempotent(): void
    {
        $application = new Application();
        $calls = 0;

        $application->onTerminate(
            static function (Application $app) use (&$calls): void {
                ++$calls;
            }
        );

        $application->terminate();
        $application->terminate();

        self::assertSame(1, $calls);
    }

    public function testBootAfterTerminationIsRejected(): void
    {
        $application = new Application();
        $application->terminate();

        $this->expectException(LogicException::class);

        $application->boot();
    }

    public function testCleanupContinuesAfterFailure(): void
    {
        $application = new Application();
        $events = [];

        $application->onTerminate(
            static function (Application $app) use (&$events): void {
                $events[] = 'first';
            }
        );

        $application->onTerminate(
            static function (Application $app) use (&$events): void {
                $events[] = 'second';

                throw new RuntimeException('Cleanup failed.');
            }
        );

        try {
            $application->terminate();
            self::fail('Expected cleanup failure.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Cleanup failed.',
                $exception->getMessage()
            );
        }

        self::assertSame(['second', 'first'], $events);
        self::assertTrue($application->isTerminated());
    }

    public function testFailedApplicationCanTerminate(): void
    {
        $application = new Application();
        $cleaned = false;

        $application->onBoot(
            static function (Application $app): void {
                throw new RuntimeException('Boot failed.');
            }
        );

        $application->onTerminate(
            static function (Application $app) use (&$cleaned): void {
                $cleaned = true;
            }
        );

        try {
            $application->boot();
        } catch (RuntimeException) {
        }

        $application->terminate();

        self::assertTrue($cleaned);
        self::assertTrue($application->isTerminated());
    }
}
