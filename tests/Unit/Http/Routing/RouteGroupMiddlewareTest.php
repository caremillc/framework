<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Routing;

use Careminate\Http\Middleware\Middleware;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\Router;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RouteGroupMiddlewareTest extends TestCase
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

    public function testGroupMiddlewareWrapsHandler(): void
    {
        $events = [];
        $router = new Router();

        $middleware = self::middleware(
            static function (Request $request, Closure $next) use (&$events): Response {
                $events[] = 'before';
                $response = $next($request);
                $events[] = 'after';

                return $response;
            },
        );

        $router->group(
            '/api',
            static function (Router $router) use (&$events): void {
                $router->get(
                    '/health',
                    static function (Request $request) use (&$events): Response {
                        $events[] = 'handler';

                        return new Response(200, [], 'OK');
                    },
                );
            },
            [$middleware],
        );

        $response = $router->dispatch(new Request('GET', '/api/health'));

        self::assertSame(200, $response->status);
        self::assertSame('OK', $response->body);
        self::assertSame(['before', 'handler', 'after'], $events);
    }

    public function testNestedGroupMiddlewareOrder(): void
    {
        $events = [];
        $router = new Router();

        $outer = self::middleware(
            static function (Request $request, Closure $next) use (&$events): Response {
                $events[] = 'outer-before';
                $response = $next($request);
                $events[] = 'outer-after';

                return $response;
            },
        );

        $inner = self::middleware(
            static function (Request $request, Closure $next) use (&$events): Response {
                $events[] = 'inner-before';
                $response = $next($request);
                $events[] = 'inner-after';

                return $response;
            },
        );

        $router->group(
            '/api',
            static function (Router $router) use ($inner, &$events): void {
                $router->group(
                    '/v1',
                    static function (Router $router) use (&$events): void {
                        $router->get(
                            '/health',
                            static function (Request $request) use (&$events): Response {
                                $events[] = 'handler';

                                return new Response(200);
                            },
                        );
                    },
                    [$inner],
                );
            },
            [$outer],
        );

        $response = $router->dispatch(
            new Request('GET', '/api/v1/health'),
        );

        self::assertSame(200, $response->status);
        self::assertSame(
            [
                'outer-before',
                'inner-before',
                'handler',
                'inner-after',
                'outer-after',
            ],
            $events,
        );
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $called = false;
        $router = new Router();

        $deny = self::middleware(
            static fn (Request $request, Closure $next): Response =>
                new Response(403, [], 'Forbidden'),
        );

        $router->group(
            '/private',
            static function (Router $router) use (&$called): void {
                $router->get(
                    '/resource',
                    static function (Request $request) use (&$called): Response {
                        $called = true;

                        return new Response(200);
                    },
                );
            },
            [$deny],
        );

        $response = $router->dispatch(
            new Request('GET', '/private/resource'),
        );

        self::assertSame(403, $response->status);
        self::assertSame('Forbidden', $response->body);
        self::assertFalse($called);
    }

    public function testMiddlewareDoesNotLeakOutsideGroup(): void
    {
        $calls = 0;
        $router = new Router();

        $middleware = self::middleware(
            static function (Request $request, Closure $next) use (&$calls): Response {
                ++$calls;

                return $next($request);
            },
        );

        $router->group(
            '/api',
            static function (Router $router): void {
                $router->get(
                    '/inside',
                    static fn (Request $request): Response => new Response(200),
                );
            },
            [$middleware],
        );

        $router->get(
            '/outside',
            static fn (Request $request): Response => new Response(200),
        );

        self::assertSame(
            200,
            $router->dispatch(new Request('GET', '/outside'))->status,
        );
        self::assertSame(0, $calls);

        self::assertSame(
            200,
            $router->dispatch(new Request('GET', '/api/inside'))->status,
        );
        self::assertSame(1, $calls);
    }

    public function testGroupStateRestoresAfterException(): void
    {
        $calls = 0;
        $router = new Router();

        $middleware = self::middleware(
            static function (Request $request, Closure $next) use (&$calls): Response {
                ++$calls;

                return $next($request);
            },
        );

        try {
            $router->group(
                '/api',
                static function (Router $router): void {
                    throw new RuntimeException('Registration failed.');
                },
                [$middleware],
            );

            self::fail('Expected registration exception.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Registration failed.',
                $exception->getMessage(),
            );
        }

        $router->get(
            '/health',
            static fn (Request $request): Response => new Response(200),
        );

        self::assertSame(
            200,
            $router->dispatch(new Request('GET', '/health'))->status,
        );
        self::assertSame(0, $calls);
    }

    public function testInvalidGroupMiddlewareIsRejected(): void
    {
        $router = new Router();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Route group middleware must implement Middleware.',
        );

        $router->group(
            '/api',
            static function (Router $router): void {
            },
            ['invalid'],
        );
    }

    public function testMiddlewareRunsForNamedDynamicRoute(): void
    {
        $router = new Router();

        $middleware = self::middleware(
            static function (Request $request, Closure $next): Response {
                return $next($request)->withHeader('X-Group', 'api');
            },
        );

        $router->group(
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
                    'api.users.show',
                );
            },
            [$middleware],
        );

        $url = $router->url('api.users.show', ['id' => 42]);
        $response = $router->dispatch(new Request('GET', $url));

        self::assertSame('/api/users/42', $url);
        self::assertSame(200, $response->status);
        self::assertSame('42', $response->body);
        self::assertSame('api', $response->header('X-Group'));
    }

    public function testUnmatchedRequestsSkipGroupMiddleware(): void
    {
        $calls = 0;
        $router = new Router();

        $middleware = self::middleware(
            static function (Request $request, Closure $next) use (&$calls): Response {
                ++$calls;

                return $next($request);
            },
        );

        $router->group(
            '/api',
            static function (Router $router): void {
                $router->get(
                    '/health',
                    static fn (Request $request): Response => new Response(200),
                );
            },
            [$middleware],
        );

        $notFound = $router->dispatch(
            new Request('GET', '/api/missing'),
        );

        $methodNotAllowed = $router->dispatch(
            new Request('POST', '/api/health'),
        );

        self::assertSame(404, $notFound->status);
        self::assertSame(405, $methodNotAllowed->status);
        self::assertSame(0, $calls);
    }
}
