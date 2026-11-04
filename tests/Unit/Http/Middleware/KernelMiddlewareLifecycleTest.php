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
use RuntimeException;

final class KernelMiddlewareLifecycleTest extends TestCase
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

    private static function createKernel(): Kernel
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/health',
            static fn (Request $request): Response =>
                new Response(200, [], 'healthy'),
        );

        return $kernel;
    }

    public function testRouteRegistrationIsRejectedAfterFirstRequest(): void
    {
        $kernel = self::createKernel();

        $response = $kernel->handle(
            new Request('GET', '/health'),
        );

        self::assertSame(200, $response->status);
        self::assertTrue($kernel->router()->isFrozen());

        $this->expectException(LogicException::class);

        $kernel->router()->get(
            '/late',
            static fn (Request $request): Response =>
                new Response(200),
        );
    }

    public function testGlobalObjectMiddlewareRegistrationIsRejectedAfterFreeze(): void
    {
        $kernel = self::createKernel();

        $response = $kernel->handle(
            new Request('GET', '/health'),
        );

        self::assertSame(200, $response->status);

        $middleware = self::middleware(
            static fn (
                Request $request,
                Closure $next,
            ): Response => $next($request),
        );

        $this->expectException(LogicException::class);

        $kernel->middleware($middleware);
    }

    public function testGlobalClosureMiddlewareRegistrationIsRejectedAfterFreeze(): void
    {
        $kernel = self::createKernel();

        $response = $kernel->handle(
            new Request('GET', '/health'),
        );

        self::assertSame(200, $response->status);

        $this->expectException(LogicException::class);

        $kernel->closureMiddleware(
            static function (
                Request $request,
                Closure $next,
            ): Response {
                $response = $next($request);

                self::assertInstanceOf(
                    Response::class,
                    $response,
                );

                return $response;
            },
        );
    }

    public function testNestedRequestHandlingIsRejected(): void
    {
        $kernel = self::createKernel();

        $nestedRejected = false;

        $kernel->middleware(
            self::middleware(
                static function (
                    Request $request,
                    Closure $next,
                ) use (
                    $kernel,
                    &$nestedRejected,
                ): Response {
                    try {
                        $kernel->handle(
                            new Request('GET', '/health'),
                        );
                    } catch (LogicException $exception) {
                        $nestedRejected = true;
                    }

                    return $next($request);
                },
            ),
        );

        $response = $kernel->handle(
            new Request('GET', '/health'),
        );

        self::assertTrue($nestedRejected);
        self::assertSame(200, $response->status);
        self::assertSame('healthy', $response->body);
    }

    public function testConfigurationMutationDuringDispatchIsRejected(): void
    {
        $kernel = self::createKernel();

        $routeRejected = false;
        $middlewareRejected = false;

        $kernel->middleware(
            self::middleware(
                static function (
                    Request $request,
                    Closure $next,
                ) use (
                    $kernel,
                    &$routeRejected,
                    &$middlewareRejected,
                ): Response {
                    try {
                        $kernel->router()->get(
                            '/injected',
                            static fn (Request $request): Response =>
                                new Response(200),
                        );
                    } catch (LogicException $exception) {
                        $routeRejected = true;
                    }

                    try {
                        $kernel->middleware(
                            self::middleware(
                                static fn (
                                    Request $request,
                                    Closure $next,
                                ): Response => $next($request),
                            ),
                        );
                    } catch (LogicException $exception) {
                        $middlewareRejected = true;
                    }

                    return $next($request);
                },
            ),
        );

        $response = $kernel->handle(
            new Request('GET', '/health'),
        );

        self::assertSame(200, $response->status);
        self::assertTrue($routeRejected);
        self::assertTrue($middlewareRejected);
        self::assertSame(1, $kernel->router()->count());

        $injected = $kernel->handle(
            new Request('GET', '/injected'),
        );

        self::assertSame(404, $injected->status);
    }

    public function testMiddlewareOrderIsStableAcrossRepeatedRequests(): void
    {
        $events = [];
        $kernel = self::createKernel();

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
                        $events[] = 'global-after';
                    }
                },
            ),
        );

        $groupMiddleware = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'group-before';

                try {
                    return $next($request);
                } finally {
                    $events[] = 'group-after';
                }
            },
        );

        $kernel->router()->group(
            '/api',
            static function (Router $router) use (&$events): void {
                $router->get(
                    '/status',
                    static function (
                        Request $request,
                    ) use (&$events): Response {
                        $events[] = 'handler';

                        return new Response(
                            200,
                            [],
                            'ready',
                        );
                    },
                );
            },
            [$groupMiddleware],
        );

        $expectedEvents = [
            'global-before',
            'group-before',
            'handler',
            'group-after',
            'global-after',
        ];

        for ($iteration = 0; $iteration < 3; ++$iteration) {
            $events = [];

            $response = $kernel->handle(
                new Request('GET', '/api/status'),
            );

            self::assertSame(200, $response->status);
            self::assertSame('ready', $response->body);
            self::assertSame($expectedEvents, $events);
        }
    }

    public function testExceptionDoesNotReopenFrozenConfiguration(): void
    {
        $kernel = self::createKernel();

        $kernel->router()->get(
            '/failure',
            static function (Request $request): Response {
                throw new RuntimeException(
                    'Expected request failure',
                );
            },
        );

        try {
            $kernel->handle(
                new Request('GET', '/failure'),
            );

            self::fail('Expected request failure.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Expected request failure',
                $exception->getMessage(),
            );
        }

        self::assertTrue($kernel->router()->isFrozen());

        $registrationRejected = false;

        try {
            $kernel->router()->get(
                '/late',
                static fn (Request $request): Response =>
                    new Response(200),
            );
        } catch (LogicException $exception) {
            $registrationRejected = true;
        }

        self::assertTrue($registrationRejected);

        $response = $kernel->handle(
            new Request('GET', '/health'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('healthy', $response->body);
        self::assertSame(2, $kernel->router()->count());
    }
}
