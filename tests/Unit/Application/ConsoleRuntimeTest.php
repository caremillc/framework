<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Application;

use ArrayObject;
use Careminate\Application\ApplicationRunner;
use Careminate\Application\Exception\InvalidExitStatusException;
use Careminate\Application\Exception\RuntimeExecutionException;
use Careminate\Application\Runtime\ConsoleRuntime;
use Careminate\Application\TerminableBootstrapperInterface;
use Careminate\Container\Container;
use Closure;
use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class ConsoleRuntimeTest extends TestCase
{
    public function testConstructionDoesNotExecuteTheOperation(): void
    {
        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $runtime = new ConsoleRuntime(
            static function (ContainerInterface $container) use ($calls): int {
                $calls->append(true);

                return 0;
            },
        );

        self::assertCount(0, $calls);
        self::assertSame(0, $runtime->run(new Container()));
        self::assertCount(1, $calls);
    }

    public function testOperationReceivesTheSuppliedContainer(): void
    {
        $container = new Container();
        $container->register('message', 'available');

        $runtime = new ConsoleRuntime(
            static function (ContainerInterface $resolver) use ($container): int {
                self::assertSame($container, $resolver);
                self::assertSame('available', $resolver->get('message'));

                return 0;
            },
        );

        self::assertSame(0, $runtime->run($container));
    }

    #[DataProvider('validStatuses')]
    public function testValidStatusesAreReturnedUnchanged(int $status): void
    {
        $runtime = new ConsoleRuntime(
            static fn (ContainerInterface $container): int => $status,
        );

        self::assertSame($status, $runtime->run(new Container()));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function validStatuses(): iterable
    {
        yield 'success' => [0];
        yield 'failure' => [1];
        yield 'application status' => [17];
        yield 'maximum' => [255];
    }

    #[DataProvider('invalidStatuses')]
    public function testOutOfRangeStatusesAreRejected(int $status): void
    {
        $runtime = new ConsoleRuntime(
            static fn (ContainerInterface $container): int => $status,
        );

        $this->expectException(InvalidExitStatusException::class);
        $this->expectExceptionMessage(
            'A console exit status must be between 0 and 255.',
        );

        $runtime->run(new Container());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidStatuses(): iterable
    {
        yield 'negative' => [-1];
        yield 'above maximum' => [256];
        yield 'minimum integer' => [PHP_INT_MIN];
        yield 'maximum integer' => [PHP_INT_MAX];
    }

    public function testDirectExecutionPreservesOperationFailure(): void
    {
        $original = new Error('Console operation failed.');

        $runtime = new ConsoleRuntime(
            static function (ContainerInterface $container) use ($original): int {
                throw $original;
            },
        );

        try {
            $runtime->run(new Container());
        } catch (Error $exception) {
            self::assertSame($original, $exception);

            return;
        }

        self::fail('The operation failure must propagate.');
    }

    public function testNonzeroStatusStillAllowsNormalTermination(): void
    {
        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();

        $runner = new ApplicationRunner(
            new Container(),
            self::cleanupBootstrapper(
                static function (ContainerInterface $container) use ($events): void {
                    $events->append('terminate');
                },
            ),
        );

        $runtime = new ConsoleRuntime(
            static function (ContainerInterface $container) use ($events): int {
                $events->append('run');

                return 17;
            },
        );

        self::assertSame(17, $runner->run($runtime));
        self::assertSame(['run', 'terminate'], $events->getArrayCopy());
    }

    public function testInvalidStatusStillTriggersRunnerCleanup(): void
    {
        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();

        $runner = new ApplicationRunner(
            new Container(),
            self::cleanupBootstrapper(
                static function (ContainerInterface $container) use ($events): void {
                    $events->append('terminate');
                },
            ),
        );

        $runtime = new ConsoleRuntime(
            static fn (ContainerInterface $container): int => 256,
        );

        try {
            $runner->run($runtime);
        } catch (RuntimeExecutionException $exception) {
            self::assertInstanceOf(
                InvalidExitStatusException::class,
                $exception->getPrevious(),
            );
            self::assertNull($exception->terminationFailure());
            self::assertSame(['terminate'], $events->getArrayCopy());

            return;
        }

        self::fail('The runner must report the invalid console status.');
    }

    /**
     * @param Closure(ContainerInterface): void $cleanup
     */
    private static function cleanupBootstrapper(
        Closure $cleanup,
    ): TerminableBootstrapperInterface {
        return new class ($cleanup) implements TerminableBootstrapperInterface {
            /**
             * @param Closure(ContainerInterface): void $cleanup
             */
            public function __construct(
                private readonly Closure $cleanup,
            ) {
            }

            public function bootstrap(ContainerInterface $container): void
            {
                // This test double has no initialization work.
            }

            public function terminate(ContainerInterface $container): void
            {
                ($this->cleanup)($container);
            }
        };
    }
}
