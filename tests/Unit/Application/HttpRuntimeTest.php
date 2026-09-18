<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Application;

use ArrayObject;
use Careminate\Application\ApplicationRunner;
use Careminate\Application\Exception\RuntimeExecutionException;
use Careminate\Application\Runtime\HttpRuntime;
use Careminate\Application\TerminableBootstrapperInterface;
use Careminate\Container\Container;
use Closure;
use Error;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HttpRuntimeTest extends TestCase
{
    public function testHandlingIsDeferredAndExactResponseIsEmitted(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $response = $this->createStub(ResponseInterface::class);

        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();

        $handler = self::handler(
            static function (ServerRequestInterface $received) use (
                $request,
                $response,
                $events,
            ): ResponseInterface {
                self::assertSame($request, $received);

                $events->append('handle');

                return $response;
            },
        );

        $runtime = new HttpRuntime(
            $request,
            $handler,
            static function (ResponseInterface $received) use (
                $response,
                $events,
            ): void {
                self::assertSame($response, $received);
                self::assertSame(['handle'], $events->getArrayCopy());

                $events->append('emit');
            },
        );

        self::assertCount(0, $events);
        self::assertSame(0, $runtime->run(new Container()));
        self::assertSame(['handle', 'emit'], $events->getArrayCopy());
    }

    public function testHttpErrorResponseIsNotAProcessExitStatus(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $response = $this->createStub(ResponseInterface::class);

        $response->method('getStatusCode')->willReturn(503);

        $runtime = new HttpRuntime(
            $request,
            self::handler(
                static fn (ServerRequestInterface $received): ResponseInterface =>
                    $response,
            ),
            static function (ResponseInterface $received): void {
                self::assertSame(503, $received->getStatusCode());
            },
        );

        self::assertSame(0, $runtime->run(new Container()));
    }

    public function testHandlerFailurePreventsEmissionAndPreservesCause(): void
    {
        $original = new Error('Handler failed.');

        /** @var ArrayObject<int, bool> $emissions */
        $emissions = new ArrayObject();

        $runtime = new HttpRuntime(
            $this->createStub(ServerRequestInterface::class),
            self::handler(
                static function (ServerRequestInterface $request) use (
                    $original,
                ): ResponseInterface {
                    throw $original;
                },
            ),
            static function (ResponseInterface $response) use ($emissions): void {
                $emissions->append(true);
            },
        );

        try {
            $runtime->run(new Container());
        } catch (Error $exception) {
            self::assertSame($original, $exception);
            self::assertCount(0, $emissions);

            return;
        }

        self::fail('The handler failure must propagate.');
    }

    public function testEmitterFailureStillAllowsApplicationCleanup(): void
    {
        $original = new Error('Emission failed.');
        $response = $this->createStub(ResponseInterface::class);

        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();

        $runtime = new HttpRuntime(
            $this->createStub(ServerRequestInterface::class),
            self::handler(
                static function (ServerRequestInterface $request) use (
                    $response,
                    $events,
                ): ResponseInterface {
                    $events->append('handle');

                    return $response;
                },
            ),
            static function (ResponseInterface $received) use (
                $original,
                $events,
            ): void {
                $events->append('emit');

                throw $original;
            },
        );

        $bootstrapper = new class ($events) implements TerminableBootstrapperInterface {
            /**
             * @param ArrayObject<int, string> $events
             */
            public function __construct(
                private readonly ArrayObject $events,
            ) {
            }

            public function bootstrap(ContainerInterface $container): void
            {
                $this->events->append('boot');
            }

            public function terminate(ContainerInterface $container): void
            {
                $this->events->append('terminate');
            }
        };

        $runner = new ApplicationRunner(new Container(), $bootstrapper);

        try {
            $runner->run($runtime);
        } catch (RuntimeExecutionException $exception) {
            self::assertSame($original, $exception->getPrevious());
            self::assertNull($exception->terminationFailure());
            self::assertSame(
                ['boot', 'handle', 'emit', 'terminate'],
                $events->getArrayCopy(),
            );

            return;
        }

        self::fail('The runner must preserve the emission failure.');
    }

    /**
     * @param Closure(ServerRequestInterface): ResponseInterface $operation
     */
    private static function handler(
        Closure $operation,
    ): RequestHandlerInterface {
        return new class ($operation) implements RequestHandlerInterface {
            /**
             * @param Closure(ServerRequestInterface): ResponseInterface $operation
             */
            public function __construct(
                private readonly Closure $operation,
            ) {
            }

            public function handle(
                ServerRequestInterface $request,
            ): ResponseInterface {
                return ($this->operation)($request);
            }
        };
    }
}
