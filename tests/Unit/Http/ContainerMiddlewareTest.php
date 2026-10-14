<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Closure;
use Flint\Container\Container;
use Flint\Container\Exception\EntryNotFoundException;
use Flint\Container\Exception\ResolutionException;
use Flint\Http\ContainerMiddleware;
use Flint\Http\Middleware;
use Flint\Http\MiddlewarePipeline;
use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

final class ContainerMiddlewareTest extends TestCase
{
    public function testResolutionIsLazyAndPreservesTransientBindings(): void
    {
        $container = new Container();
        $created = 0;
        $request = new Request('GET', '/example');
        $response = ResponseFactory::text('Ready');
        $next = self::destination($response);

        $container->bind(
            'middleware',
            static function (ContainerInterface $services) use (
                &$created,
                $request,
            ): Middleware {
                ++$created;

                return self::middleware(
                    static function (
                        Request $received,
                        RequestHandler $next,
                    ) use ($request): Response {
                        self::assertSame($request, $received);

                        return $next->handle($received);
                    },
                );
            },
        );

        $adapter = new ContainerMiddleware($container, 'middleware');

        self::assertSame(0, $created);

        $first = $adapter->process($request, $next);
        $second = $adapter->process($request, $next);

        self::assertSame($response, $first);
        self::assertSame($response, $second);
        self::assertSame(2, $created);
    }

    public function testSingletonLifetimeBelongsToTheContainer(): void
    {
        $container = new Container();
        $created = 0;
        $entered = 0;

        $container->singleton(
            'middleware',
            static function (ContainerInterface $services) use (
                &$created,
                &$entered,
            ): Middleware {
                ++$created;

                return self::middleware(
                    static function (
                        Request $request,
                        RequestHandler $next,
                    ) use (&$entered): Response {
                        ++$entered;

                        return $next->handle($request);
                    },
                );
            },
        );

        $adapter = new ContainerMiddleware($container, 'middleware');
        $next = self::destination();

        $adapter->process(new Request(), $next);
        $adapter->process(new Request(), $next);

        self::assertSame(1, $created);
        self::assertSame(2, $entered);
    }

    public function testResolvedServicesMustImplementMiddleware(): void
    {
        $container = new Container();
        $container->instance('middleware', new stdClass());

        $adapter = new ContainerMiddleware($container, 'middleware');

        $this->expectException(UnexpectedValueException::class);

        $adapter->process(new Request(), self::destination());
    }

    public function testMissingServicesFailWhenInvoked(): void
    {
        $adapter = new ContainerMiddleware(
            new Container(),
            'missing',
        );

        $this->expectException(EntryNotFoundException::class);

        $adapter->process(new Request(), self::destination());
    }

    public function testConstructionFailureDoesNotPreventRetry(): void
    {
        $container = new Container();
        $attempts = 0;
        $failure = new RuntimeException('Middleware construction failed.');

        $container->bind(
            'middleware',
            static function (ContainerInterface $services) use (
                &$attempts,
                $failure,
            ): Middleware {
                ++$attempts;

                if ($attempts === 1) {
                    throw $failure;
                }

                return self::middleware(
                    static fn (
                        Request $request,
                        RequestHandler $next,
                    ): Response => $next->handle($request),
                );
            },
        );

        $adapter = new ContainerMiddleware($container, 'middleware');
        $next = self::destination();

        try {
            $adapter->process(new Request(), $next);
            self::fail('Expected middleware construction to fail.');
        } catch (ResolutionException $exception) {
            self::assertSame($failure, $exception->getPrevious());
        }

        self::assertSame(
            'Ready',
            $adapter->process(new Request(), $next)->body(),
        );

        self::assertSame(2, $attempts);
    }

    public function testMiddlewareExceptionsPropagateUnchanged(): void
    {
        $container = new Container();
        $failure = new RuntimeException('Middleware execution failed.');

        $container->instance(
            'middleware',
            self::middleware(
                static function (
                    Request $request,
                    RequestHandler $next,
                ) use ($failure): Response {
                    throw $failure;
                },
            ),
        );

        $adapter = new ContainerMiddleware($container, 'middleware');

        try {
            $adapter->process(new Request(), self::destination());
            self::fail('Expected middleware execution to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public function testEmptyServiceIdentifiersAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ContainerMiddleware(new Container(), '');
    }

    public function testEarlierShortCircuitPreventsResolution(): void
    {
        $container = new Container();
        $created = 0;

        $container->bind(
            'middleware',
            static function (ContainerInterface $services) use (
                &$created,
            ): Middleware {
                ++$created;

                return self::middleware(
                    static fn (
                        Request $request,
                        RequestHandler $next,
                    ): Response => $next->handle($request),
                );
            },
        );

        $pipeline = new MiddlewarePipeline(
            self::destination(),
            self::middleware(
                static fn (
                    Request $request,
                    RequestHandler $next,
                ): Response => ResponseFactory::text('Forbidden', 403),
            ),
            new ContainerMiddleware($container, 'middleware'),
        );

        self::assertSame(
            403,
            $pipeline->handle(new Request())->status(),
        );

        self::assertSame(0, $created);
    }

    private static function destination(
        ?Response $response = null,
    ): RequestHandler {
        return new class (
            $response ?? ResponseFactory::text('Ready'),
        ) implements RequestHandler {
            public function __construct(
                private readonly Response $response,
            ) {
            }

            public function handle(Request $request): Response
            {
                return $this->response;
            }
        };
    }

    /**
     * @param Closure(Request, RequestHandler): Response $callback
     */
    private static function middleware(Closure $callback): Middleware
    {
        return new class ($callback) implements Middleware {
            /**
             * @param Closure(Request, RequestHandler): Response $callback
             */
            public function __construct(
                private readonly Closure $callback,
            ) {
            }

            public function process(
                Request $request,
                RequestHandler $next,
            ): Response {
                return ($this->callback)($request, $next);
            }
        };
    }
}
