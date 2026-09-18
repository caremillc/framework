<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Application;

use ArrayObject;
use Careminate\Application\ApplicationRunner;
use Careminate\Application\Exception\RuntimeExecutionException;
use Careminate\Application\Exception\RuntimeStateException;
use Careminate\Application\Runtime\ConsoleRuntime;
use Careminate\Application\Runtime\ScopedBatchRuntime;
use Careminate\Application\RuntimeInterface;
use Careminate\Application\TerminableBootstrapperInterface;
use Careminate\Container\Container;
use Careminate\Container\Exception\ScopeStateException;
use Closure;
use Error;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;

final class ScopedBatchRuntimeTest extends TestCase
{
    public function testOperationSourceIsDeferredUntilExecution(): void
    {
        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $runtime = new ScopedBatchRuntime(
            static function () use ($calls): iterable {
                $calls->append(true);

                return [];
            },
        );

        self::assertCount(0, $calls);
        self::assertSame(0, $runtime->run(new Container()));
        self::assertCount(1, $calls);
    }

    public function testScopedServicesAreIsolatedAndSingletonsAreShared(): void
    {
        $container = new Container();

        $container->scoped(
            'scoped',
            static fn (ContainerInterface $resolver): stdClass => new stdClass(),
        );
        $container->singleton(
            'singleton',
            static fn (ContainerInterface $resolver): stdClass => new stdClass(),
        );
        $container->freeze();

        /** @var ArrayObject<int, stdClass> $scoped */
        $scoped = new ArrayObject();

        /** @var ArrayObject<int, stdClass> $singletons */
        $singletons = new ArrayObject();

        $operation = new ConsoleRuntime(
            static function (ContainerInterface $resolver) use (
                $scoped,
                $singletons,
            ): int {
                $service = $resolver->get('scoped');
                $singleton = $resolver->get('singleton');

                self::assertInstanceOf(stdClass::class, $service);
                self::assertInstanceOf(stdClass::class, $singleton);
                self::assertSame($service, $resolver->get('scoped'));

                $scoped->append($service);
                $singletons->append($singleton);

                return 0;
            },
        );

        $runtime = new ScopedBatchRuntime(
            static fn (): iterable => [$operation, $operation],
        );

        self::assertSame(0, $runtime->run($container));
        self::assertCount(2, $scoped);
        self::assertCount(2, $singletons);
        self::assertNotSame($scoped[0], $scoped[1]);
        self::assertSame($singletons[0], $singletons[1]);

        $this->expectException(ScopeStateException::class);

        $container->get('scoped');
    }

    public function testNonzeroResultStopsBeforeRequestingAnotherOperation(): void
    {
        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();

        $runtime = new ScopedBatchRuntime(
            static function () use ($events): iterable {
                $events->append('yield.first');

                yield new ConsoleRuntime(
                    static function (ContainerInterface $resolver) use (
                        $events,
                    ): int {
                        $events->append('run.first');

                        return 17;
                    },
                );

                $events->append('yield.second');

                yield new ConsoleRuntime(
                    static fn (ContainerInterface $resolver): int => 0,
                );
            },
        );

        $container = new Container();

        self::assertSame(17, $runtime->run($container));
        self::assertSame(
            ['yield.first', 'run.first'],
            $events->getArrayCopy(),
        );

        self::assertSame(
            0,
            $container->runInScope(
                static fn (ContainerInterface $resolver): int => 0,
            ),
        );
    }

    public function testOperationFailureEndsScopeBeforeApplicationCleanup(): void
    {
        $original = new Error('Operation failed.');

        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();

        $container = new Container();

        $bootstrapper = self::bootstrapper(
            static function (ContainerInterface $resolver) use ($events): void {
                $events->append('boot');
            },
            static function (ContainerInterface $resolver) use (
                $container,
                $events,
            ): void {
                self::assertSame($container, $resolver);

                // A fresh scope can start only after the failed one has ended.
                self::assertSame(
                    'scope ended',
                    $container->runInScope(
                        static fn (ContainerInterface $inner): string =>
                            'scope ended',
                    ),
                );

                $events->append('terminate');
            },
        );

        $runtime = new ScopedBatchRuntime(
            static function () use ($events, $original): iterable {
                yield new ConsoleRuntime(
                    static function (ContainerInterface $resolver) use (
                        $events,
                        $original,
                    ): int {
                        $events->append('run');

                        throw $original;
                    },
                );

                $events->append('unreachable');
            },
        );

        $runner = new ApplicationRunner($container, $bootstrapper);

        try {
            $runner->run($runtime);
        } catch (RuntimeExecutionException $exception) {
            self::assertSame($original, $exception->getPrevious());
            self::assertNull($exception->terminationFailure());
            self::assertSame(
                ['boot', 'run', 'terminate'],
                $events->getArrayCopy(),
            );

            return;
        }

        self::fail('The operation failure must propagate through the runner.');
    }

    public function testApplicationBootsAndTerminatesOnceForMultipleOperations(): void
    {
        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();

        $operation = new ConsoleRuntime(
            static function (ContainerInterface $resolver) use ($events): int {
                $events->append('run');

                return 0;
            },
        );

        $runtime = new ScopedBatchRuntime(
            static fn (): iterable => [$operation, $operation],
        );

        $runner = new ApplicationRunner(
            new Container(),
            self::bootstrapper(
                static function (ContainerInterface $resolver) use ($events): void {
                    $events->append('boot');
                },
                static function (ContainerInterface $resolver) use ($events): void {
                    $events->append('terminate');
                },
            ),
        );

        self::assertSame(0, $runner->run($runtime));
        self::assertSame(
            ['boot', 'run', 'run', 'terminate'],
            $events->getArrayCopy(),
        );
    }

    public function testSourceFailureIsPreserved(): void
    {
        $original = new Error('Operation source failed.');

        $runtime = new ScopedBatchRuntime(
            static function () use ($original): iterable {
                throw $original;
            },
        );

        try {
            $runtime->run(new Container());
        } catch (Error $exception) {
            self::assertSame($original, $exception);

            return;
        }

        self::fail('An operation source failure must propagate.');
    }

    public function testForeignContainerIsRejectedBeforeReadingSource(): void
    {
        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $runtime = new ScopedBatchRuntime(
            static function () use ($calls): iterable {
                $calls->append(true);

                return [];
            },
        );

        $container = new class () implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new Error('No lookup is expected.');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        try {
            $runtime->run($container);
        } catch (RuntimeStateException $exception) {
            self::assertSame(
                'Scoped batch execution requires a Careminate container.',
                $exception->getMessage(),
            );
            self::assertCount(0, $calls);

            return;
        }

        self::fail('A container without Careminate scopes must be rejected.');
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
