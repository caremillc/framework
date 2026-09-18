<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Application;

use ArrayObject;
use Careminate\Application\ApplicationKernel;
use Careminate\Application\Exception\BootstrapException;
use Careminate\Application\Exception\InvalidLifecycleTransitionException;
use Careminate\Application\Exception\TerminationException;
use Careminate\Application\Internal\ApplicationState;
use Careminate\Application\TerminableBootstrapperInterface;
use Careminate\Container\Container;
use Closure;
use Error;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class ApplicationTerminationTest extends TestCase
{
    public function testCleanupRunsInReverseOrderAndOnlyOnce(): void
    {
        $container = new Container();

        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();

        $kernel = new ApplicationKernel(
            $container,
            self::bootstrapper(
                static function (ContainerInterface $resolver) use ($events): void {
                    $events->append('boot.first');
                },
                static function (ContainerInterface $resolver) use (
                    $container,
                    $events,
                ): void {
                    self::assertSame($container, $resolver);
                    $events->append('stop.first');
                },
            ),
            self::bootstrapper(
                static function (ContainerInterface $resolver) use ($events): void {
                    $events->append('boot.second');
                },
                static function (ContainerInterface $resolver) use (
                    $container,
                    $events,
                ): void {
                    self::assertSame($container, $resolver);
                    $events->append('stop.second');
                },
            ),
        );

        $kernel->boot();
        $kernel->boot();
        $kernel->terminate();
        $kernel->terminate();

        self::assertSame(
            ['boot.first', 'boot.second', 'stop.second', 'stop.first'],
            $events->getArrayCopy(),
        );
        self::assertSame(ApplicationState::Terminated, $kernel->state());
    }

    public function testEmptyKernelCanTerminateAfterBoot(): void
    {
        $kernel = new ApplicationKernel(new Container());

        $kernel->boot();
        $kernel->terminate();

        self::assertSame(ApplicationState::Terminated, $kernel->state());

        $kernel->terminate();

        self::assertSame(ApplicationState::Terminated, $kernel->state());
    }

    public function testTerminationBeforeBootIsRejectedWithoutChangingState(): void
    {
        $kernel = new ApplicationKernel(new Container());

        try {
            $kernel->terminate();
        } catch (InvalidLifecycleTransitionException $exception) {
            self::assertSame(
                'The application cannot transition from "created" to "terminating".',
                $exception->getMessage(),
            );
            self::assertSame(ApplicationState::Created, $kernel->state());

            $kernel->boot();
            $kernel->terminate();

            self::assertSame(ApplicationState::Terminated, $kernel->state());

            return;
        }

        self::fail('An unbooted kernel must reject termination.');
    }

    public function testBootFailureCleansUpOnlyCompletedBootstrappers(): void
    {
        $original = new Error('Boot failed.');

        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();

        $kernel = new ApplicationKernel(
            new Container(),
            self::bootstrapper(
                static function (ContainerInterface $resolver) use ($events): void {
                    $events->append('boot.first');
                },
                static function (ContainerInterface $resolver) use ($events): void {
                    $events->append('stop.first');
                },
            ),
            self::bootstrapper(
                static function (ContainerInterface $resolver) use (
                    $events,
                    $original,
                ): void {
                    $events->append('boot.failing');

                    throw $original;
                },
                static function (ContainerInterface $resolver) use ($events): void {
                    $events->append('stop.failing');
                },
            ),
            self::bootstrapper(
                static function (ContainerInterface $resolver) use ($events): void {
                    $events->append('boot.unreachable');
                },
                static function (ContainerInterface $resolver) use ($events): void {
                    $events->append('stop.unreachable');
                },
            ),
        );

        try {
            $kernel->boot();
        } catch (BootstrapException $exception) {
            self::assertSame($original, $exception->getPrevious());
            self::assertSame([], $exception->cleanupFailures());
            self::assertSame(ApplicationState::Failed, $kernel->state());

            $kernel->terminate();

            self::assertSame(
                ['boot.first', 'boot.failing', 'stop.first'],
                $events->getArrayCopy(),
            );
            self::assertSame(ApplicationState::Failed, $kernel->state());

            return;
        }

        self::fail('Boot must fail.');
    }

    public function testBootFailureRemainsPrimaryWhenCleanupAlsoFails(): void
    {
        $bootFailure = new Error('Boot failed.');
        $firstCleanupFailure = new Error('First cleanup failed.');
        $secondCleanupFailure = new Error('Second cleanup failed.');

        $kernel = new ApplicationKernel(
            new Container(),
            self::bootstrapper(
                self::noOperation(),
                static function (ContainerInterface $resolver) use (
                    $firstCleanupFailure,
                ): void {
                    throw $firstCleanupFailure;
                },
            ),
            self::bootstrapper(
                self::noOperation(),
                static function (ContainerInterface $resolver) use (
                    $secondCleanupFailure,
                ): void {
                    throw $secondCleanupFailure;
                },
            ),
            self::bootstrapper(
                static function (ContainerInterface $resolver) use (
                    $bootFailure,
                ): void {
                    throw $bootFailure;
                },
                self::noOperation(),
            ),
        );

        try {
            $kernel->boot();
        } catch (BootstrapException $exception) {
            self::assertSame($bootFailure, $exception->getPrevious());
            self::assertSame(
                [$secondCleanupFailure, $firstCleanupFailure],
                $exception->cleanupFailures(),
            );
            self::assertSame(ApplicationState::Failed, $kernel->state());

            return;
        }

        self::fail('Boot must report its original failure.');
    }

    public function testTerminationAttemptsAllHandlersAndPreservesEveryFailure(): void
    {
        $firstFailure = new Error('First cleanup failed.');
        $secondFailure = new Error('Second cleanup failed.');

        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();

        $kernel = new ApplicationKernel(
            new Container(),
            self::bootstrapper(
                self::noOperation(),
                static function (ContainerInterface $resolver) use (
                    $events,
                    $firstFailure,
                ): void {
                    $events->append('first');

                    throw $firstFailure;
                },
            ),
            self::bootstrapper(
                self::noOperation(),
                static function (ContainerInterface $resolver) use ($events): void {
                    $events->append('middle');
                },
            ),
            self::bootstrapper(
                self::noOperation(),
                static function (ContainerInterface $resolver) use (
                    $events,
                    $secondFailure,
                ): void {
                    $events->append('second');

                    throw $secondFailure;
                },
            ),
        );

        $kernel->boot();

        try {
            $kernel->terminate();
        } catch (TerminationException $exception) {
            self::assertSame(
                'Application termination failed.',
                $exception->getMessage(),
            );
            self::assertSame($secondFailure, $exception->getPrevious());
            self::assertSame(
                [$secondFailure, $firstFailure],
                $exception->failures(),
            );
            self::assertSame(ApplicationState::Failed, $kernel->state());

            $kernel->terminate();

            self::assertSame(
                ['second', 'middle', 'first'],
                $events->getArrayCopy(),
            );

            return;
        }

        self::fail('Termination must report its cleanup failures.');
    }

    public function testRecursiveTerminationIsReportedAndRemainingCleanupRuns(): void
    {
        /** @var ArrayObject<string, ApplicationKernel> $kernels */
        $kernels = new ArrayObject();

        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();

        $kernel = new ApplicationKernel(
            new Container(),
            self::bootstrapper(
                self::noOperation(),
                static function (ContainerInterface $resolver) use ($events): void {
                    $events->append('remaining');
                },
            ),
            self::bootstrapper(
                self::noOperation(),
                static function (ContainerInterface $resolver) use (
                    $kernels,
                    $events,
                ): void {
                    $kernel = $kernels->offsetGet('application');

                    self::assertInstanceOf(ApplicationKernel::class, $kernel);
                    self::assertSame(
                        ApplicationState::Terminating,
                        $kernel->state(),
                    );

                    $events->append('recursive');
                    $kernel->terminate();
                },
            ),
        );

        $kernels->offsetSet('application', $kernel);
        $kernel->boot();

        try {
            $kernel->terminate();
        } catch (TerminationException $exception) {
            self::assertCount(1, $exception->failures());
            self::assertInstanceOf(
                InvalidLifecycleTransitionException::class,
                $exception->getPrevious(),
            );
            self::assertSame(
                ['recursive', 'remaining'],
                $events->getArrayCopy(),
            );
            self::assertSame(ApplicationState::Failed, $kernel->state());

            return;
        }

        self::fail('Recursive termination must be reported.');
    }

    /**
     * @return Closure(ContainerInterface): void
     */
    private static function noOperation(): Closure
    {
        return static function (ContainerInterface $container): void {
        };
    }

    /**
     * @param Closure(ContainerInterface): void $boot
     * @param Closure(ContainerInterface): void $terminate
     */
    private static function bootstrapper(
        Closure $boot,
        Closure $terminate,
    ): TerminableBootstrapperInterface {
        return new class ($boot, $terminate) implements TerminableBootstrapperInterface {
            /**
             * @param Closure(ContainerInterface): void $boot
             * @param Closure(ContainerInterface): void $terminate
             */
            public function __construct(
                private readonly Closure $boot,
                private readonly Closure $terminate,
            ) {
            }

            public function bootstrap(ContainerInterface $container): void
            {
                ($this->boot)($container);
            }

            public function terminate(ContainerInterface $container): void
            {
                ($this->terminate)($container);
            }
        };
    }
}
