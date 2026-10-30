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

final class KernelRequestIsolationTest extends TestCase
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

    private static function kernel(): Kernel
    {
        return new Kernel(new Application());
    }

    public function testDynamicParametersDoNotLeakIntoStaticRoute(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/users/{id}',
            static fn (Request $request): Response =>
                new Response(
                    200,
                    [],
                    $request->routeParameter('id') ?? 'missing',
                ),
        );

        $kernel->router()->get(
            '/health',
            static fn (Request $request): Response =>
                new Response(
                    200,
                    [],
                    $request->routeParameter('id') ?? 'clean',
                ),
        );

        $first = $kernel->handle(
            new Request('GET', '/users/42'),
        );

        $second = $kernel->handle(
            new Request('GET', '/health'),
        );

        self::assertSame('42', $first->body);
        self::assertSame('clean', $second->body);
    }

    public function testDynamicParametersAreIsolatedBetweenRequests(): void
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

        foreach (['11', '22', '33', '44'] as $id) {
            $response = $kernel->handle(
                new Request('GET', '/users/' . $id),
            );

            self::assertSame(200, $response->status);
            self::assertSame($id, $response->body);
        }
    }

    public function testStaticRouteTakesPrecedenceOverDynamicRoute(): void
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

        $static = $kernel->handle(
            new Request('GET', '/users/me'),
        );

        $dynamic = $kernel->handle(
            new Request('GET', '/users/15'),
        );

        self::assertSame('static', $static->body);
        self::assertSame('dynamic', $dynamic->body);
    }

    public function testResponseHeadersRemainIsolated(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/first',
            static fn (Request $request): Response =>
                new Response(200, [], 'first')
                    ->withHeader('X-Request-Only', 'first'),
        );

        $kernel->router()->get(
            '/second',
            static fn (Request $request): Response =>
                new Response(200, [], 'second'),
        );

        $first = $kernel->handle(
            new Request('GET', '/first'),
        );

        $second = $kernel->handle(
            new Request('GET', '/second'),
        );

        self::assertSame(
            'first',
            $first->header('X-Request-Only'),
        );

        self::assertNull(
            $second->header('X-Request-Only'),
        );

        self::assertSame('second', $second->body);
    }

    public function testResponseStatusAndBodyRemainIsolated(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/created',
            static fn (Request $request): Response =>
                new Response(201, [], 'created'),
        );

        $kernel->router()->get(
            '/empty',
            static fn (Request $request): Response =>
                new Response(204),
        );

        $created = $kernel->handle(
            new Request('GET', '/created'),
        );

        $empty = $kernel->handle(
            new Request('GET', '/empty'),
        );

        self::assertSame(201, $created->status);
        self::assertSame('created', $created->body);
        self::assertSame(204, $empty->status);
        self::assertSame('', $empty->body);
    }

    public function testNotFoundDoesNotExecuteRouteGroupMiddleware(): void
    {
        $kernel = self::kernel();
        $calls = 0;

        $group = self::middleware(
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
                    '/health',
                    static fn (Request $request): Response =>
                        new Response(200),
                );
            },
            [$group],
        );

        $response = $kernel->handle(
            new Request('GET', '/api/missing'),
        );

        self::assertSame(404, $response->status);
        self::assertSame(0, $calls);
    }

    public function testMethodNotAllowedDoesNotExecuteGroupMiddleware(): void
    {
        $kernel = self::kernel();
        $calls = 0;

        $group = self::middleware(
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
                    '/health',
                    static fn (Request $request): Response =>
                        new Response(200),
                );
            },
            [$group],
        );

        $response = $kernel->handle(
            new Request('POST', '/api/health'),
        );

        self::assertSame(405, $response->status);
        self::assertSame('GET', $response->header('Allow'));
        self::assertSame(0, $calls);
    }

    public function testShortCircuitDoesNotContaminateLaterRequest(): void
    {
        $kernel = self::kernel();
        $handlerCalls = 0;

        $guard = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ): Response {
                return new Response(403, [], 'denied');
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
            '/public',
            static fn (Request $request): Response =>
                new Response(200, [], 'public'),
        );

        $denied = $kernel->handle(
            new Request('GET', '/private/data'),
        );

        $allowed = $kernel->handle(
            new Request('GET', '/public'),
        );

        self::assertSame(403, $denied->status);
        self::assertSame(200, $allowed->status);
        self::assertSame('public', $allowed->body);
        self::assertSame(0, $handlerCalls);
    }

    public function testSiblingGroupsHaveIndependentMiddleware(): void
    {
        $kernel = self::kernel();
        $events = [];

        $firstMiddleware = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'first';

                return $next($request);
            },
        );

        $secondMiddleware = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'second';

                return $next($request);
            },
        );

        $kernel->router()->group(
            '/first',
            static function (Router $router): void {
                $router->get(
                    '/data',
                    static fn (Request $request): Response =>
                        new Response(200, [], 'first'),
                );
            },
            [$firstMiddleware],
        );

        $kernel->router()->group(
            '/second',
            static function (Router $router): void {
                $router->get(
                    '/data',
                    static fn (Request $request): Response =>
                        new Response(200, [], 'second'),
                );
            },
            [$secondMiddleware],
        );

        $first = $kernel->handle(
            new Request('GET', '/first/data'),
        );

        self::assertSame('first', $first->body);
        self::assertSame(['first'], $events);

        $events = [];

        $second = $kernel->handle(
            new Request('GET', '/second/data'),
        );

        self::assertSame('second', $second->body);
        self::assertSame(['second'], $events);
    }

    public function testInvalidDynamicParameterSkipsGroupMiddleware(): void
    {
        $kernel = self::kernel();
        $calls = 0;

        $group = self::middleware(
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
                    '/users/{id}',
                    static fn (Request $request): Response =>
                        new Response(200),
                    ['id' => '[0-9]+'],
                );
            },
            [$group],
        );

        $invalid = $kernel->handle(
            new Request('GET', '/api/users/abc'),
        );

        $valid = $kernel->handle(
            new Request('GET', '/api/users/123'),
        );

        self::assertSame(404, $invalid->status);
        self::assertSame(200, $valid->status);
        self::assertSame(1, $calls);
    }

    public function testGlobalMiddlewareRunsAcrossDifferentRoutes(): void
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

        $kernel->router()->get(
            '/one',
            static fn (Request $request): Response =>
                new Response(200),
        );

        $kernel->router()->get(
            '/two',
            static fn (Request $request): Response =>
                new Response(200),
        );

        $first = $kernel->handle(
            new Request('GET', '/one'),
        );

        $second = $kernel->handle(
            new Request('GET', '/two'),
        );

        $missing = $kernel->handle(
            new Request('GET', '/missing'),
        );

        self::assertSame(200, $first->status);
        self::assertSame(200, $second->status);
        self::assertSame(404, $missing->status);
        self::assertSame(3, $calls);
    }

    public function testExceptionDoesNotContaminateSubsequentResponse(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/failure',
            static function (Request $request): Response {
                throw new RuntimeException('Request failed');
            },
        );

        $kernel->router()->get(
            '/success',
            static fn (Request $request): Response =>
                new Response(200, [], 'recovered'),
        );

        try {
            $kernel->handle(
                new Request('GET', '/failure'),
            );

            self::fail('Expected request exception.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Request failed',
                $exception->getMessage(),
            );
        }

        $response = $kernel->handle(
            new Request('GET', '/success'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('recovered', $response->body);
    }

    public function testRejectedRegistrationPreservesRouteTable(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/health',
            static fn (Request $request): Response =>
                new Response(200),
        );

        $kernel->handle(
            new Request('GET', '/health'),
        );

        $before = $kernel->router()->count();

        try {
            $kernel->router()->get(
                '/injected',
                static fn (Request $request): Response =>
                    new Response(200),
            );

            self::fail('Expected frozen router exception.');
        } catch (LogicException $exception) {
            self::assertSame(
                'Cannot register routes after the router is frozen.',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            $before,
            $kernel->router()->count(),
        );

        $response = $kernel->handle(
            new Request('GET', '/injected'),
        );

        self::assertSame(404, $response->status);
    }

    public function testNamedRouteGenerationRemainsConsistent(): void
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
            ['id' => '[0-9]+'],
            'users.show',
        );

        $firstUrl = $kernel->router()->url(
            'users.show',
            ['id' => 15],
        );

        $first = $kernel->handle(
            new Request('GET', $firstUrl),
        );

        $secondUrl = $kernel->router()->url(
            'users.show',
            ['id' => 27],
        );

        $second = $kernel->handle(
            new Request('GET', $secondUrl),
        );

        self::assertSame('/users/15', $firstUrl);
        self::assertSame('/users/27', $secondUrl);
        self::assertSame('15', $first->body);
        self::assertSame('27', $second->body);
        self::assertTrue($kernel->router()->isFrozen());
    }

    public function testFrozenConfigurationSurvivesRepeatedFailures(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/failure',
            static function (Request $request): Response {
                throw new RuntimeException('Expected failure');
            },
        );

        $kernel->router()->get(
            '/health',
            static fn (Request $request): Response =>
                new Response(200, [], 'healthy'),
        );

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            try {
                $kernel->handle(
                    new Request('GET', '/failure'),
                );

                self::fail('Expected failure.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'Expected failure',
                    $exception->getMessage(),
                );
            }

            self::assertTrue($kernel->router()->isFrozen());
            self::assertSame(2, $kernel->router()->count());
        }

        $response = $kernel->handle(
            new Request('GET', '/health'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('healthy', $response->body);
    }
}
