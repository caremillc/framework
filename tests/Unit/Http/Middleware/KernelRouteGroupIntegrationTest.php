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
use LogicException;
use PHPUnit\Framework\TestCase;

final class KernelRouteGroupIntegrationTest extends TestCase
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

    public function testCompleteMiddlewareExecutionOrder(): void
    {
        $events = [];
        $kernel = new Kernel(new Application());

        $kernel->closureMiddleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'closure-before';
                $response = $next($request);
                self::assertInstanceOf(Response::class, $response);
                $events[] = 'closure-after';

                return $response;
            },
        );

        $kernel->middleware(
            self::middleware(
                static function (
                    Request $request,
                    Closure $next,
                ) use (&$events): Response {
                    $events[] = 'global-before';
                    $response = $next($request);
                    $events[] = 'global-after';

                    return $response;
                },
            ),
        );

        $outer = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'outer-before';
                $response = $next($request);
                $events[] = 'outer-after';

                return $response;
            },
        );

        $inner = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'inner-before';
                $response = $next($request);
                $events[] = 'inner-after';

                return $response;
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
                            '/health',
                            static function (
                                Request $request,
                            ) use (&$events): Response {
                                $events[] = 'handler';

                                return new Response(
                                    200,
                                    [],
                                    'healthy',
                                );
                            },
                        );
                    },
                    [$inner],
                );
            },
            [$outer],
        );

        $response = $kernel->handle(
            new Request('GET', '/api/v1/health'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('healthy', $response->body);

        self::assertSame(
            [
                'closure-before',
                'global-before',
                'outer-before',
                'inner-before',
                'handler',
                'inner-after',
                'outer-after',
                'global-after',
                'closure-after',
            ],
            $events,
        );
    }

    public function testResponseHeadersPropagateAcrossAllLayers(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->closureMiddleware(
            static function (
                Request $request,
                Closure $next,
            ): Response {
                $response = $next($request);
                self::assertInstanceOf(Response::class, $response);

                return $response->withHeader(
                    'X-Closure',
                    'active',
                );
            },
        );

        $kernel->middleware(
            self::middleware(
                static fn (
                    Request $request,
                    Closure $next,
                ): Response => $next($request)->withHeader(
                    'X-Global',
                    'active',
                ),
            ),
        );

        $groupMiddleware = self::middleware(
            static fn (
                Request $request,
                Closure $next,
            ): Response => $next($request)->withHeader(
                'X-Group',
                'active',
            ),
        );

        $kernel->router()->group(
            '/api',
            static function (Router $router): void {
                $router->get(
                    '/headers',
                    static fn (Request $request): Response =>
                        new Response(200, [], 'OK')->withHeader(
                            'X-Handler',
                            'active',
                        ),
                );
            },
            [$groupMiddleware],
        );

        $response = $kernel->handle(
            new Request('GET', '/api/headers'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('OK', $response->body);
        self::assertSame('active', $response->header('X-Handler'));
        self::assertSame('active', $response->header('X-Group'));
        self::assertSame('active', $response->header('X-Global'));
        self::assertSame('active', $response->header('X-Closure'));
    }

    public function testGlobalClosureCanShortCircuitAllOtherLayers(): void
    {
        $events = [];
        $kernel = new Kernel(new Application());

        $kernel->closureMiddleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'closure';

                return new Response(401, [], 'Unauthorized');
            },
        );

        $kernel->middleware(
            self::middleware(
                static function (
                    Request $request,
                    Closure $next,
                ) use (&$events): Response {
                    $events[] = 'global';

                    return $next($request);
                },
            ),
        );

        $group = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'group';

                return $next($request);
            },
        );

        $kernel->router()->group(
            '/private',
            static function (Router $router) use (&$events): void {
                $router->get(
                    '/data',
                    static function (
                        Request $request,
                    ) use (&$events): Response {
                        $events[] = 'handler';

                        return new Response(200);
                    },
                );
            },
            [$group],
        );

        $response = $kernel->handle(
            new Request('GET', '/private/data'),
        );

        self::assertSame(401, $response->status);
        self::assertSame(['closure'], $events);
    }

    public function testGroupShortCircuitStillUnwindsGlobalLayers(): void
    {
        $events = [];
        $kernel = new Kernel(new Application());

        $kernel->closureMiddleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'closure-before';

                $response = $next($request);
                self::assertInstanceOf(Response::class, $response);

                $events[] = 'closure-after';

                return $response;
            },
        );

        $kernel->middleware(
            self::middleware(
                static function (
                    Request $request,
                    Closure $next,
                ) use (&$events): Response {
                    $events[] = 'global-before';
                    $response = $next($request);
                    $events[] = 'global-after';

                    return $response;
                },
            ),
        );

        $deny = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'group-denied';

                return new Response(403, [], 'Forbidden');
            },
        );

        $kernel->router()->group(
            '/private',
            static function (Router $router) use (&$events): void {
                $router->get(
                    '/data',
                    static function (
                        Request $request,
                    ) use (&$events): Response {
                        $events[] = 'handler';

                        return new Response(200);
                    },
                );
            },
            [$deny],
        );

        $response = $kernel->handle(
            new Request('GET', '/private/data'),
        );

        self::assertSame(403, $response->status);
        self::assertSame('Forbidden', $response->body);

        self::assertSame(
            [
                'closure-before',
                'global-before',
                'group-denied',
                'global-after',
                'closure-after',
            ],
            $events,
        );
    }

    public function testDynamicRouteParametersReachGroupMiddleware(): void
    {
        $kernel = new Kernel(new Application());
        $seenId = null;

        $group = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$seenId): Response {
                $seenId = $request->routeParameter('id');

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
                    'api.users.show',
                );
            },
            [$group],
        );

        $url = $kernel->router()->url(
            'api.users.show',
            ['id' => 42],
        );

        $response = $kernel->handle(
            new Request('GET', $url),
        );

        self::assertSame('/api/users/42', $url);
        self::assertSame('42', $seenId);
        self::assertSame('42', $response->body);
    }

    public function testUnmatchedRoutesSkipGroupMiddleware(): void
    {
        $kernel = new Kernel(new Application());
        $groupCalls = 0;
        $globalCalls = 0;

        $kernel->middleware(
            self::middleware(
                static function (
                    Request $request,
                    Closure $next,
                ) use (&$globalCalls): Response {
                    ++$globalCalls;

                    return $next($request);
                },
            ),
        );

        $group = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$groupCalls): Response {
                ++$groupCalls;

                return $next($request);
            },
        );

        $kernel->router()->group(
            '/api',
            static function (Router $router): void {
                $router->get(
                    '/health',
                    static fn (Request $request): Response =>
                        new Response(200),
                );
            },
            [$group],
        );

        $notFound = $kernel->handle(
            new Request('GET', '/api/missing'),
        );

        $methodNotAllowed = $kernel->handle(
            new Request('POST', '/api/health'),
        );

        self::assertSame(404, $notFound->status);
        self::assertSame(405, $methodNotAllowed->status);
        self::assertSame(0, $groupCalls);
        self::assertSame(2, $globalCalls);
    }

    public function testKernelFreezesRouteAndMiddlewareRegistration(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/health',
            static fn (Request $request): Response =>
                new Response(200),
        );

        $response = $kernel->handle(
            new Request('GET', '/health'),
        );

        self::assertSame(200, $response->status);
        self::assertTrue($kernel->router()->isFrozen());

        $this->expectException(LogicException::class);

        $kernel->router()->group(
            '/late',
            static function (Router $router): void {
                $router->get(
                    '/route',
                    static fn (Request $request): Response =>
                        new Response(200),
                );
            },
        );
    }
}
