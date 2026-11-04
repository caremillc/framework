<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http;

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

final class KernelStressResilienceTest extends TestCase
{
    private static function kernel(): Kernel
    {
        return new Kernel(new Application());
    }

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

            /** @param Closure(Request): Response $next */
            public function process(
                Request $request,
                Closure $next,
            ): Response {
                return ($this->callback)($request, $next);
            }
        };
    }

    private static function registerHealth(Kernel $kernel): void
    {
        $kernel->router()->get(
            '/health',
            static fn (Request $request): Response =>
                new Response(200, [], 'healthy'),
        );
    }

    public function testOneHundredSequentialRequestsSucceed(): void
    {
        $kernel = self::kernel();
        self::registerHealth($kernel);

        for ($i = 0; $i < 100; ++$i) {
            $response = $kernel->handle(
                new Request('GET', '/health'),
            );

            self::assertSame(200, $response->status);
            self::assertSame('healthy', $response->body);
        }
    }

    public function testRepeatedRequestsReturnDistinctResponseObjects(): void
    {
        $kernel = self::kernel();
        self::registerHealth($kernel);

        $previous = null;

        for ($i = 0; $i < 30; ++$i) {
            $response = $kernel->handle(
                new Request('GET', '/health'),
            );

            if ($previous !== null) {
                self::assertNotSame($previous, $response);
            }

            self::assertSame('healthy', $response->body);
            $previous = $response;
        }
    }

    public function testAlternatingResponseStatusesRemainCorrect(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/ok',
            static fn (Request $request): Response =>
                new Response(200, [], 'ok'),
        );

        $kernel->router()->post(
            '/created',
            static fn (Request $request): Response =>
                new Response(201, [], 'created'),
        );

        for ($i = 0; $i < 40; ++$i) {
            $created = $i % 2 === 1;

            $response = $kernel->handle(
                new Request(
                    $created ? 'POST' : 'GET',
                    $created ? '/created' : '/ok',
                ),
            );

            self::assertSame(
                $created ? 201 : 200,
                $response->status,
            );

            self::assertSame(
                $created ? 'created' : 'ok',
                $response->body,
            );
        }
    }

    public function testResponseHeadersRemainIsolatedOverManyRequests(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/tagged',
            static fn (Request $request): Response =>
                new Response(200)
                    ->withHeader('X-Tagged', 'yes'),
        );

        $kernel->router()->get(
            '/plain',
            static fn (Request $request): Response =>
                new Response(200),
        );

        for ($i = 0; $i < 25; ++$i) {
            $tagged = $kernel->handle(
                new Request('GET', '/tagged'),
            );

            $plain = $kernel->handle(
                new Request('GET', '/plain'),
            );

            self::assertSame('yes', $tagged->header('X-Tagged'));
            self::assertNull($plain->header('X-Tagged'));
        }
    }

    public function testResponseBodiesDoNotLeakAcrossRoutes(): void
    {
        $kernel = self::kernel();

        foreach (['alpha', 'beta', 'gamma'] as $name) {
            $kernel->router()->get(
                '/' . $name,
                static fn (Request $request): Response =>
                    new Response(200, [], $name),
            );
        }

        for ($i = 0; $i < 60; ++$i) {
            $name = ['alpha', 'beta', 'gamma'][$i % 3];

            $response = $kernel->handle(
                new Request('GET', '/' . $name),
            );

            self::assertSame($name, $response->body);
        }
    }

    public function testDynamicParametersRemainIsolatedOverManyRequests(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/users/{id}',
            static fn (Request $request): Response =>
                new Response(
                    200,
                    [],
                    $request->routeParameter('id') ?? '',
                ),
        );

        for ($i = 1; $i <= 100; ++$i) {
            $response = $kernel->handle(
                new Request('GET', '/users/' . $i),
            );

            self::assertSame((string) $i, $response->body);
        }
    }

    public function testStaticAndDynamicRoutesRemainDeterministic(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/users/{id}',
            static fn (Request $request): Response =>
                new Response(200, [], 'dynamic'),
        );

        $kernel->router()->get(
            '/users/me',
            static fn (Request $request): Response =>
                new Response(200, [], 'static'),
        );

        for ($i = 0; $i < 30; ++$i) {
            $target = $i % 2 === 0
                ? '/users/me'
                : '/users/42';

            $response = $kernel->handle(
                new Request('GET', $target),
            );

            self::assertSame(
                $i % 2 === 0 ? 'static' : 'dynamic',
                $response->body,
            );
        }
    }

    public function testNotFoundRequestsDoNotAffectLaterMatches(): void
    {
        $kernel = self::kernel();
        self::registerHealth($kernel);

        for ($i = 0; $i < 30; ++$i) {
            $missing = $kernel->handle(
                new Request('GET', '/missing/' . $i),
            );

            $healthy = $kernel->handle(
                new Request('GET', '/health'),
            );

            self::assertSame(404, $missing->status);
            self::assertSame(200, $healthy->status);
            self::assertSame('healthy', $healthy->body);
        }
    }

    public function testMethodNotAllowedDoesNotAffectLaterMatches(): void
    {
        $kernel = self::kernel();
        self::registerHealth($kernel);

        for ($i = 0; $i < 30; ++$i) {
            $rejected = $kernel->handle(
                new Request('POST', '/health'),
            );

            $accepted = $kernel->handle(
                new Request('GET', '/health'),
            );

            self::assertSame(405, $rejected->status);
            self::assertSame('GET, HEAD, OPTIONS', $rejected->header('Allow'));
            self::assertSame(200, $accepted->status);
        }
    }

    public function testGlobalMiddlewareRunsExactlyOncePerRequest(): void
    {
        $kernel = self::kernel();
        $calls = 0;

        $kernel->middleware(
            self::middleware(
                static function (
                    Request $request,
                    Closure $next,
                ) use (&$calls): Response {
                    ++$calls;

                    return $next($request);
                },
            ),
        );

        self::registerHealth($kernel);

        for ($i = 1; $i <= 50; ++$i) {
            $response = $kernel->handle(
                new Request('GET', '/health'),
            );

            self::assertSame(200, $response->status);
            self::assertSame($i, $calls);
        }
    }

    public function testGroupMiddlewareRunsOnlyForMatchingRoutes(): void
    {
        $kernel = self::kernel();
        $calls = 0;

        $guard = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$calls): Response {
                ++$calls;

                return $next($request);
            },
        );

        $kernel->router()->group(
            '/api',
            static function (Router $router): void {
                $router->get(
                    '/data',
                    static fn (Request $request): Response =>
                        new Response(200),
                );
            },
            [$guard],
        );

        self::registerHealth($kernel);

        for ($i = 1; $i <= 25; ++$i) {
            $matched = $kernel->handle(
                new Request('GET', '/api/data'),
            );

            $unmatched = $kernel->handle(
                new Request('GET', '/health'),
            );

            self::assertSame(200, $matched->status);
            self::assertSame(200, $unmatched->status);
            self::assertSame($i, $calls);
        }
    }

    public function testShortCircuitDecisionsAreReevaluated(): void
    {
        $kernel = self::kernel();
        $handlerCalls = 0;

        $guard = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ): Response {
                if ($request->header('X-Permit') !== 'yes') {
                    return new Response(403, [], 'denied');
                }

                return $next($request);
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

                        return new Response(200, [], 'allowed');
                    },
                );
            },
            [$guard],
        );

        for ($i = 0; $i < 40; ++$i) {
            $allowed = $i % 2 === 1;

            $response = $kernel->handle(
                new Request(
                    'GET',
                    '/private/data',
                    $allowed ? ['X-Permit' => 'yes'] : [],
                ),
            );

            self::assertSame(
                $allowed ? 200 : 403,
                $response->status,
            );
        }

        self::assertSame(20, $handlerCalls);
    }

    public function testMiddlewareUnwindsInOrderAcrossRepeatedRequests(): void
    {
        $kernel = self::kernel();
        $events = [];

        foreach (['outer', 'inner'] as $name) {
            $kernel->middleware(
                self::middleware(
                    static function (
                        Request $request,
                        Closure $next,
                    ) use (&$events, $name): Response {
                        $events[] = $name . '-before';

                        try {
                            return $next($request);
                        } finally {
                            $events[] = $name . '-after';
                        }
                    },
                ),
            );
        }

        self::registerHealth($kernel);

        $expected = [
            'outer-before',
            'inner-before',
            'inner-after',
            'outer-after',
        ];

        for ($i = 0; $i < 20; ++$i) {
            $events = [];

            $response = $kernel->handle(
                new Request('GET', '/health'),
            );

            self::assertSame(200, $response->status);
            self::assertSame($expected, $events);
        }
    }

    public function testGlobalMiddlewareRunsForRoutingErrors(): void
    {
        $kernel = self::kernel();
        $calls = 0;

        $kernel->middleware(
            self::middleware(
                static function (
                    Request $request,
                    Closure $next,
                ) use (&$calls): Response {
                    ++$calls;

                    return $next($request);
                },
            ),
        );

        self::registerHealth($kernel);

        for ($i = 0; $i < 20; ++$i) {
            $missing = $kernel->handle(
                new Request('GET', '/missing'),
            );

            $wrongMethod = $kernel->handle(
                new Request('POST', '/health'),
            );

            self::assertSame(404, $missing->status);
            self::assertSame(405, $wrongMethod->status);
        }

        self::assertSame(40, $calls);
    }

    public function testRouteMiddlewareNeverRunsAfterGlobalDenial(): void
    {
        $kernel = self::kernel();
        $routeCalls = 0;

        $kernel->middleware(
            self::middleware(
                static fn (
                    Request $request,
                    Closure $next,
                ): Response => new Response(403, [], 'blocked'),
            ),
        );

        $routeMiddleware = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$routeCalls): Response {
                ++$routeCalls;

                return $next($request);
            },
        );

        $kernel->router()->group(
            '/private',
            static function (Router $router): void {
                $router->get(
                    '/data',
                    static fn (Request $request): Response =>
                        new Response(200),
                );
            },
            [$routeMiddleware],
        );

        for ($i = 0; $i < 25; ++$i) {
            $response = $kernel->handle(
                new Request('GET', '/private/data'),
            );

            self::assertSame(403, $response->status);
        }

        self::assertSame(0, $routeCalls);
    }

    public function testRepeatedHandlerExceptionsDoNotLockKernel(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/failure',
            static function (Request $request): Response {
                throw new RuntimeException('handler failed');
            },
        );

        self::registerHealth($kernel);

        for ($i = 0; $i < 20; ++$i) {
            try {
                $kernel->handle(
                    new Request('GET', '/failure'),
                );

                self::fail('Expected handler failure.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'handler failed',
                    $exception->getMessage(),
                );
            }

            $response = $kernel->handle(
                new Request('GET', '/health'),
            );

            self::assertSame(200, $response->status);
        }
    }

    public function testRepeatedMiddlewareExceptionsDoNotLockKernel(): void
    {
        $kernel = self::kernel();

        $throwing = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ): Response {
                throw new RuntimeException('middleware failed');
            },
        );

        $kernel->router()->group(
            '/failure',
            static function (Router $router): void {
                $router->get(
                    '/data',
                    static fn (Request $request): Response =>
                        new Response(200),
                );
            },
            [$throwing],
        );

        self::registerHealth($kernel);

        for ($i = 0; $i < 20; ++$i) {
            try {
                $kernel->handle(
                    new Request('GET', '/failure/data'),
                );

                self::fail('Expected middleware failure.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'middleware failed',
                    $exception->getMessage(),
                );
            }

            self::assertSame(
                200,
                $kernel->handle(
                    new Request('GET', '/health'),
                )->status,
            );
        }
    }

    public function testConfigurationRemainsFrozenAcrossManyRequests(): void
    {
        $kernel = self::kernel();
        self::registerHealth($kernel);

        for ($i = 0; $i < 50; ++$i) {
            $response = $kernel->handle(
                new Request('GET', '/health'),
            );

            self::assertSame(200, $response->status);
            self::assertTrue($kernel->router()->isFrozen());
            self::assertSame(1, $kernel->router()->count());
        }

        try {
            $kernel->router()->get(
                '/late',
                static fn (Request $request): Response =>
                    new Response(200),
            );

            self::fail('Expected frozen router rejection.');
        } catch (LogicException $exception) {
            self::assertSame(
                'Cannot register routes after the router is frozen.',
                $exception->getMessage(),
            );
        }

        self::assertSame(1, $kernel->router()->count());
    }

    public function testIndependentKernelsMaintainSeparateRoutes(): void
    {
        $first = self::kernel();
        $second = self::kernel();

        $first->router()->get(
            '/first',
            static fn (Request $request): Response =>
                new Response(200, [], 'first'),
        );

        $second->router()->get(
            '/second',
            static fn (Request $request): Response =>
                new Response(200, [], 'second'),
        );

        for ($i = 0; $i < 20; ++$i) {
            self::assertSame(
                200,
                $first->handle(
                    new Request('GET', '/first'),
                )->status,
            );

            self::assertSame(
                404,
                $first->handle(
                    new Request('GET', '/second'),
                )->status,
            );

            self::assertSame(
                200,
                $second->handle(
                    new Request('GET', '/second'),
                )->status,
            );

            self::assertSame(
                404,
                $second->handle(
                    new Request('GET', '/first'),
                )->status,
            );
        }
    }

    public function testIndependentKernelsMaintainSeparateMiddleware(): void
    {
        $first = self::kernel();
        $second = self::kernel();

        $firstCalls = 0;
        $secondCalls = 0;

        $first->middleware(
            self::middleware(
                static function (
                    Request $request,
                    Closure $next,
                ) use (&$firstCalls): Response {
                    ++$firstCalls;

                    return $next($request);
                },
            ),
        );

        $second->middleware(
            self::middleware(
                static function (
                    Request $request,
                    Closure $next,
                ) use (&$secondCalls): Response {
                    ++$secondCalls;

                    return $next($request);
                },
            ),
        );

        self::registerHealth($first);
        self::registerHealth($second);

        for ($i = 1; $i <= 20; ++$i) {
            self::assertSame(
                200,
                $first->handle(
                    new Request('GET', '/health'),
                )->status,
            );

            self::assertSame($i, $firstCalls);
            self::assertSame($i - 1, $secondCalls);

            self::assertSame(
                200,
                $second->handle(
                    new Request('GET', '/health'),
                )->status,
            );

            self::assertSame($i, $secondCalls);
        }
    }
}
