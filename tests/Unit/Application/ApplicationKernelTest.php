<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Application;

use ArrayObject;
use Careminate\Application\ApplicationKernel;
use Careminate\Application\BootstrapperInterface;
use Careminate\Application\Exception\BootstrapException;
use Careminate\Application\Exception\InvalidLifecycleTransitionException;
use Careminate\Application\Internal\ApplicationState;
use Careminate\Container\Container;
use Closure;
use Error;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class ApplicationKernelTest extends TestCase
{
    public function testConstructionDoesNotRunBootstrappers(): void
    {
        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();

        $kernel = new ApplicationKernel(
            new Container(),
            self::bootstrapper(
                static function (ContainerInterface $container) use (
                    $events,
                ): void {
                    $events->append('boot');
                },
            ),
        );

        self::assertSame(ApplicationState::Created, $kernel->state());
        self::assertCount(0, $events);
    }

    public function testEmptyBootstrapSequenceCanBoot(): void
    {
        $kernel = new ApplicationKernel(new Container());

        $kernel->boot();

        self::assertSame(ApplicationState::Booted, $kernel->state());
    }

    public function testBootstrappersRunInOrderWithTheSuppliedContainer(): void
    {
        $container = new Container();
        $container->register('configuration', 'available');

        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();

        $kernel = new ApplicationKernel(
            $container,
            self::bootstrapper(
                static function (ContainerInterface $resolver) use (
                    $container,
                    $events,
                ): void {
                    self::assertSame($container, $resolver);
                    self::assertSame(
                        'available',
                        $resolver->get('configuration'),
                    );

                    $events->append('first');
                },
            ),
            self::bootstrapper(
                static function (ContainerInterface $resolver) use (
                    $container,
                    $events,
                ): void {
                    self::assertSame($container, $resolver);
                    self::assertSame(['first'], $events->getArrayCopy());

                    $events->append('second');
                },
            ),
        );

        $kernel->boot();

        self::assertSame(['first', 'second'], $events->getArrayCopy());
        self::assertSame(ApplicationState::Booted, $kernel->state());
    }

    public function testSuccessfulBootIsIdempotent(): void
    {
        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $kernel = new ApplicationKernel(
            new Container(),
            self::bootstrapper(
                static function (ContainerInterface $container) use (
                    $calls,
                ): void {
                    $calls->append(true);
                },
            ),
        );

        $kernel->boot();
        $kernel->boot();

        self::assertCount(1, $calls);
        self::assertSame(ApplicationState::Booted, $kernel->state());
    }

    public function testFailureStopsTheSequenceAndPreservesItsCause(): void
    {
        $original = new Error('Bootstrap operation failed.');

        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();

        $kernel = new ApplicationKernel(
            new Container(),
            self::bootstrapper(
                static function (ContainerInterface $container) use (
                    $events,
                ): void {
                    $events->append('first');
                },
            ),
            self::bootstrapper(
                static function (ContainerInterface $container) use (
                    $events,
                    $original,
                ): void {
                    $events->append('failing');

                    throw $original;
                },
            ),
            self::bootstrapper(
                static function (ContainerInterface $container) use (
                    $events,
                ): void {
                    $events->append('unreachable');
                },
            ),
        );

        $failure = self::captureBootstrapFailure($kernel);

        self::assertSame('Application boot failed.', $failure->getMessage());
        self::assertSame($original, $failure->getPrevious());
        self::assertSame(['first', 'failing'], $events->getArrayCopy());
        self::assertSame(ApplicationState::Failed, $kernel->state());
    }

    public function testFailedKernelCannotRetryBoot(): void
    {
        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $kernel = new ApplicationKernel(
            new Container(),
            self::bootstrapper(
                static function (ContainerInterface $container) use (
                    $calls,
                ): void {
                    $calls->append(true);

                    throw new Error('Failed.');
                },
            ),
        );

        $failure = self::captureBootstrapFailure($kernel);

        self::assertInstanceOf(Error::class, $failure->getPrevious());
        self::assertCount(1, $calls);

        try {
            $kernel->boot();
        } catch (InvalidLifecycleTransitionException $exception) {
            self::assertSame(
                'The application cannot transition from "failed" to "booting".',
                $exception->getMessage(),
            );
            self::assertCount(1, $calls);
            self::assertSame(ApplicationState::Failed, $kernel->state());

            return;
        }

        self::fail('A failed kernel must reject another boot attempt.');
    }

    public function testRecursiveBootFailsWithoutRunningLaterBootstrappers(): void
    {
        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();

        /** @var ArrayObject<string, ApplicationKernel> $kernels */
        $kernels = new ArrayObject();

        $recursive = self::bootstrapper(
            static function (ContainerInterface $container) use (
                $kernels,
                $events,
            ): void {
                self::assertTrue($kernels->offsetExists('application'));

                $kernel = $kernels->offsetGet('application');

                self::assertInstanceOf(ApplicationKernel::class, $kernel);
                self::assertSame(ApplicationState::Booting, $kernel->state());

                $events->append('recursive');
                $kernel->boot();
            },
        );

        $kernel = new ApplicationKernel(
            new Container(),
            $recursive,
            self::bootstrapper(
                static function (ContainerInterface $container) use (
                    $events,
                ): void {
                    $events->append('unreachable');
                },
            ),
        );

        $kernels->offsetSet('application', $kernel);

        $failure = self::captureBootstrapFailure($kernel);
        $previous = $failure->getPrevious();

        self::assertInstanceOf(
            InvalidLifecycleTransitionException::class,
            $previous,
        );
        self::assertSame(
            'The application cannot transition from "booting" to "booting".',
            $previous->getMessage(),
        );
        self::assertSame(['recursive'], $events->getArrayCopy());
        self::assertSame(ApplicationState::Failed, $kernel->state());
    }

    public function testKernelInstancesBootIndependently(): void
    {
        /** @var ArrayObject<int, bool> $calls */
        $calls = new ArrayObject();

        $bootstrapper = self::bootstrapper(
            static function (ContainerInterface $container) use (
                $calls,
            ): void {
                $calls->append(true);
            },
        );

        $first = new ApplicationKernel(new Container(), $bootstrapper);
        $second = new ApplicationKernel(new Container(), $bootstrapper);

        $first->boot();

        self::assertSame(ApplicationState::Booted, $first->state());
        self::assertSame(ApplicationState::Created, $second->state());
        self::assertCount(1, $calls);

        $second->boot();

        self::assertSame(ApplicationState::Booted, $second->state());
        self::assertCount(2, $calls);
    }

    /**
     * @param Closure(ContainerInterface): void $operation
     */
    private static function bootstrapper(
        Closure $operation,
    ): BootstrapperInterface {
        return new class ($operation) implements BootstrapperInterface {
            /**
             * @param Closure(ContainerInterface): void $operation
             */
            public function __construct(
                private readonly Closure $operation,
            ) {
            }

            public function bootstrap(ContainerInterface $container): void
            {
                ($this->operation)($container);
            }
        };
    }

    private static function captureBootstrapFailure(
        ApplicationKernel $kernel,
    ): BootstrapException {
        try {
            $kernel->boot();
        } catch (BootstrapException $exception) {
            return $exception;
        }

        self::fail('Application boot must fail.');
    }
}
