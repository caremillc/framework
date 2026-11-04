<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Middleware;

use Careminate\Foundation\Application;
use Careminate\Http\Kernel;
use Careminate\Http\Middleware\Middleware;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\Router;
use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KernelMiddlewareExceptionRecoveryTest extends TestCase
{
    /**
     * @param Closure(
     *     Request,
     *     Closure(Request): Response
     * ): Response $callback
     */
    private static function middleware(Closure $callback): Middleware
    {
        return new class ($callback) implements Middleware {
            /**
             * @param Closure(
             *     Request,
             *     Closure(Request): Response
             * ): Response $callback
             */
            public function __construct(
                private readonly Closure $callback,
            ) {
            }

            /**
             * @param Closure(Request): Response $next
             */
            public function process(
                Request $request,
                Closure $next,
            ): Response {
                return ($this->callback)($request, $next);
            }
        };
    }

    public function testHandlerExceptionPropagatesThroughKernel(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/failure',
            static function (Request $request): Response {
                throw new RuntimeException('Handler failure');
            },
        );

        try {
            $kernel->handle(new Request('GET', '/failure'));
            self::fail('Expected handler exception.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Handler failure',
                $exception->getMessage(),
            );
        }
    }

    public function testKernelRecoversAfterHandlerException(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/failure',
            static function (Request $request): Response {
                throw new RuntimeException('Handler failure');
            },
        );

        $kernel->router()->get(
            '/health',
            static fn (Request $request): Response =>
                new Response(200, [], 'healthy'),
        );

        try {
            $kernel->handle(new Request('GET', '/failure'));
            self::fail('Expected handler exception.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Handler failure',
                $exception->getMessage(),
            );
        }

        $response = $kernel->handle(
            new Request('GET', '/health'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('healthy', $response->body);
        self::assertTrue($kernel->router()->isFrozen());
    }

    public function testMiddlewareFinallyBlocksExecuteInReverseOrder(): void
    {
        $events = [];
        $kernel = new Kernel(new Application());


        $kernel->closureMiddleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'closure-before';

                try {
                    $response = $next($request);

                    self::assertInstanceOf(
                        Response::class,
                        $response,
                    );

                    return $response;
                } finally {
                    $events[] = 'closure-finally';
                }
            },
        );


        $kernel->middleware(
            self::middleware(
                static function (
                    Request $request,
                    Closure $next,
                ) use (&$events): Response {
                    $events[] = 'global-before';

                    try {
                        return $next($request);
                    } finally {
                        $events[] = 'global-finally';
                    }
                },
            ),
        );

        $outer = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'outer-before';

                try {
                    return $next($request);
                } finally {
                    $events[] = 'outer-finally';
                }
            },
        );

        $inner = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'inner-before';

                try {
                    return $next($request);
                } finally {
                    $events[] = 'inner-finally';
                }
            },
        );

        $kernel->router()->group(
            '/api',
            static function (Router $router) use (
                $inner,
                &$events,
            ): void {
                $router->group(
                    '/v1',
                    static function (Router $router) use (
                        &$events,
                    ): void {
                        $router->get(
                            '/failure',
                            static function (
                                Request $request,
                            ) use (&$events): Response {
                                $events[] = 'handler';

                                throw new RuntimeException(
                                    'Nested handler failure',
                                );
                            },
                        );
                    },
                    [$inner],
                );
            },
            [$outer],
        );

        try {
            $kernel->handle(
                new Request('GET', '/api/v1/failure'),
            );

            self::fail('Expected nested handler exception.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Nested handler failure',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            [
                'closure-before',
                'global-before',
                'outer-before',
                'inner-before',
                'handler',
                'inner-finally',
                'outer-finally',
                'global-finally',
                'closure-finally',
            ],
            $events,
        );
    }

    public function testKernelRecoversAfterGroupMiddlewareException(): void
    {
        $kernel = new Kernel(new Application());
        $handlerCalls = 0;

        $throwingMiddleware = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ): Response {
                throw new RuntimeException(
                    'Group middleware failure',
                );
            },
        );

        $kernel->router()->group(
            '/private',
            static function (Router $router) use (
                &$handlerCalls,
            ): void {
                $router->get(
                    '/failure',
                    static function (
                        Request $request,
                    ) use (&$handlerCalls): Response {
                        ++$handlerCalls;

                        return new Response(200);
                    },
                );
            },
            [$throwingMiddleware],
        );

        $kernel->router()->get(
            '/health',
            static fn (Request $request): Response =>
                new Response(200, [], 'healthy'),
        );

        try {
            $kernel->handle(
                new Request('GET', '/private/failure'),
            );

            self::fail('Expected middleware exception.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Group middleware failure',
                $exception->getMessage(),
            );
        }

        self::assertSame(0, $handlerCalls);

        $response = $kernel->handle(
            new Request('GET', '/health'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('healthy', $response->body);
        self::assertSame(0, $handlerCalls);
    }

    public function testShortCircuitResponseDoesNotCauseRecoveryFailure(): void
    {
        $kernel = new Kernel(new Application());
        $groupCalls = 0;
        $handlerCalls = 0;

        $guard = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$groupCalls): Response {
                ++$groupCalls;

                return new Response(403, [], 'Forbidden');
            },
        );

        $kernel->router()->group(
            '/private',
            static function (Router $router) use (
                &$handlerCalls,
            ): void {
                $router->get(
                    '/data',
                    static function (
                        Request $request,
                    ) use (&$handlerCalls): Response {
                        ++$handlerCalls;

                        return new Response(200);
                    },
                );
            },
            [$guard],
        );

        $kernel->router()->get(
            '/health',
            static fn (Request $request): Response =>
                new Response(200, [], 'healthy'),
        );

        $denied = $kernel->handle(
            new Request('GET', '/private/data'),
        );

        $healthy = $kernel->handle(
            new Request('GET', '/health'),
        );

        self::assertSame(403, $denied->status);
        self::assertSame('Forbidden', $denied->body);
        self::assertSame(200, $healthy->status);
        self::assertSame('healthy', $healthy->body);
        self::assertSame(1, $groupCalls);
        self::assertSame(0, $handlerCalls);
    }

    public function testDynamicRouteMiddlewareExceptionPreservesRecovery(): void
    {
        $kernel = new Kernel(new Application());
        $observedId = null;

        $middleware = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$observedId): Response {
                $observedId = $request->routeParameter('id');

                if ($observedId === '13') {
                    throw new RuntimeException(
                        'Dynamic route failure',
                    );
                }

                return $next($request);
            },
        );

        $kernel->router()->group(
            '/api',
            static function (Router $router): void {
                $router->get(
                    '/users/{id}',
                    static fn (Request $request): Response =>
                        new Response(
                            200,
                            [],
                            $request->routeParameter('id') ?? '',
                        ),
                    ['id' => '[0-9]+'],
                );
            },
            [$middleware],
        );

        try {
            $kernel->handle(
                new Request('GET', '/api/users/13'),
            );

            self::fail('Expected dynamic route exception.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Dynamic route failure',
                $exception->getMessage(),
            );
        }

        self::assertSame('13', $observedId);

        $response = $kernel->handle(
            new Request('GET', '/api/users/42'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('42', $response->body);
        self::assertSame('42', $observedId);
    }


}
