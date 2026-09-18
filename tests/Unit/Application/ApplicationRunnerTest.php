<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Application;

use ArrayObject;
use Careminate\Application\ApplicationRunner;
use Careminate\Application\Exception\BootstrapException;
use Careminate\Application\Exception\RuntimeExecutionException;
use Careminate\Application\Exception\RuntimeStateException;
use Careminate\Application\Exception\TerminationException;
use Careminate\Application\RuntimeInterface;
use Careminate\Application\TerminableBootstrapperInterface;
use Careminate\Container\Container;
use Closure;
use Error;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class ApplicationRunnerTest extends TestCase
{
    public function testBootExecutionAndTerminationRunInOrder(): void
    {
        $container = new Container();

        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();

        $runner = new ApplicationRunner(
            $container,
            self::bootstrapper(
                static function (ContainerInterface $resolver) use ($events): void {
                    $events->append('boot');
                },
                static function (ContainerInterface $resolver) use (
                    $container,
                    $events,
                ): void {
                    self::assertSame($container, $resolver);
                    $events->append('terminate');
                },
            ),
        );

        $result = $runner->run(self::runtime(
            static function (ContainerInterface $resolver) use (
                $container,
                $events,
            ): int {
                self::assertSame($container, $resolver);
                self::assertSame(['boot'], $events->getArrayCopy());

                $events->append('run');

                return 17;
            },
        ));

        self::assertSame(17, $result);
        self::assertSame(
            ['boot', 'run', 'terminate'],
            $events->getArrayCopy(),
        );
    }

    public function testExecutionFailureStillTerminates(): void
    {
        $original = new Error('Runtime failed.');

        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();

        $runner = new ApplicationRunner(
            new Container(),
            self::bootstrapper(
                self::noOperation(),
                static function (ContainerInterface $resolver) use ($events): void {
                    $events->append('terminate');
                },
            ),
        );

        try {
            $runner->run(self::runtime(
                static function (ContainerInterface $resolver) use (
                    $original,
                ): int {
                    throw $original;
                },
            ));
        } catch (RuntimeExecutionException $exception) {
            self::assertSame($original, $exception->getPrevious());
            self::assertNull($exception->terminationFailure());
            self::assertSame(['terminate'], $events->getArrayCopy());

            return;
        }

        self::fail('The runtime failure must be reported.');
    }

    public function testExecutionAndTerminationFailuresAreBothPreserved(): void
    {
        $executionFailure = new Error('Runtime failed.');
        $cleanupFailure = new Error('Cleanup failed.');

        $runner = new ApplicationRunner(
            new Container(),
            self::bootstrapper(
                self::noOperation(),
                static function (ContainerInterface $resolver) use (
                    $cleanupFailure,
                ): void {
                    throw $cleanupFailure;
                },
            ),
        );

        try {
            $runner->run(self::runtime(
                static function (ContainerInterface $resolver) use (
                    $executionFailure,
                ): int {
                    throw $executionFailure;
                },
            ));
        } catch (RuntimeExecutionException $exception) {
            self::assertSame(
                'Application runtime execution failed.',
                $exception->getMessage(),
            );
            self::assertSame($executionFailure, $exception->getPrevious());

            $termination = $exception->terminationFailure();

            self::assertInstanceOf(TerminationException::class, $termination);
            self::assertSame($cleanupFailure, $termination->getPrevious());
            self::assertSame([$cleanupFailure], $termination->failures());

            return;
        }

        self::fail('Both failures must be reported.');
    }

    public function testTerminationFailurePreventsReturningTheRuntimeResult(): void
    {
        $original = new Error('Cleanup failed.');

        $runner = new ApplicationRunner(
            new Container(),
            self::bootstrapper(
                self::noOperation(),
                static function (ContainerInterface $resolver) use (
                    $original,
                ): void {
                    throw $original;
                },
            ),
        );

        try {
            $runner->run(self::runtime(
                static fn (ContainerInterface $resolver): int => 0,
            ));
        } catch (TerminationException $exception) {
            self::assertSame($original, $exception->getPrevious());

            return;
        }

        self::fail('A cleanup failure must not become a successful result.');
    }

    public function testBootFailureSkipsRuntimeAndPreservesBootstrapFailure(): void
    {
        $original = new Error('Boot failed.');

        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();

        $runner = new ApplicationRunner(
            new Container(),
            self::bootstrapper(
                self::noOperation(),
                static function (ContainerInterface $resolver) use ($events): void {
                    $events->append('cleanup.completed');
                },
            ),
            self::bootstrapper(
                static function (ContainerInterface $resolver) use (
                    $original,
                ): void {
                    throw $original;
                },
                static function (ContainerInterface $resolver) use ($events): void {
                    $events->append('cleanup.incomplete');
                },
            ),
        );

        $runtime = self::runtime(
            static function (ContainerInterface $resolver) use ($events): int {
                $events->append('run');

                return 0;
            },
        );

        try {
            $runner->run($runtime);
        } catch (BootstrapException $exception) {
            self::assertSame($original, $exception->getPrevious());
            self::assertSame(
                ['cleanup.completed'],
                $events->getArrayCopy(),
            );

            $this->expectException(RuntimeStateException::class);

            $runner->run($runtime);

            return;
        }

        self::fail('Bootstrap failure must prevent runtime execution.');
    }

    public function testCompletedRunnerRejectsAnotherExecution(): void
    {
        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $runtime = self::runtime(
            static function (ContainerInterface $resolver) use ($calls): int {
                $calls->append(true);

                return 0;
            },
        );

        $runner = new ApplicationRunner(new Container());

        self::assertSame(0, $runner->run($runtime));

        try {
            $runner->run($runtime);
        } catch (RuntimeStateException $exception) {
            self::assertSame(
                'An application runner can execute only once.',
                $exception->getMessage(),
            );
            self::assertCount(1, $calls);

            return;
        }

        self::fail('A completed runner must reject another execution.');
    }

    public function testRecursiveExecutionIsRejectedAndCleanupStillRuns(): void
    {
        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();

        $runner = new ApplicationRunner(
            new Container(),
            self::bootstrapper(
                self::noOperation(),
                static function (ContainerInterface $resolver) use ($events): void {
                    $events->append('cleanup');
                },
            ),
        );

        $nested = self::runtime(
            static function (ContainerInterface $resolver) use ($events): int {
                $events->append('nested');

                return 0;
            },
        );

        try {
            $runner->run(self::runtime(
                static function (ContainerInterface $resolver) use (
                    $runner,
                    $nested,
                ): int {
                    return $runner->run($nested);
                },
            ));
        } catch (RuntimeExecutionException $exception) {
            self::assertInstanceOf(
                RuntimeStateException::class,
                $exception->getPrevious(),
            );
            self::assertNull($exception->terminationFailure());
            self::assertSame(['cleanup'], $events->getArrayCopy());

            return;
        }

        self::fail('Recursive execution must be rejected.');
    }

    /**
     * @param Closure(ContainerInterface): int $operation
     */
    private static function runtime(Closure $operation): RuntimeInterface
    {
        return new class ($operation) implements RuntimeInterface {
            /**
             * @param Closure(ContainerInterface): int $operation
             */
            public function __construct(
                private readonly Closure $operation,
            ) {
            }

            public function run(ContainerInterface $container): int
            {
                return ($this->operation)($container);
            }
        };
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
