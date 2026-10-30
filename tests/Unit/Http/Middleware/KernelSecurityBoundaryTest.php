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

final class KernelSecurityBoundaryTest extends TestCase
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

    private static function tokenGuard(): Middleware
    {
        return self::middleware(
            static function (
                Request $request,
                Closure $next,
            ): Response {
                if ($request->header('X-Access-Token') !== 'valid') {
                    return new Response(
                        401,
                        [],
                        'Unauthorized',
                    );
                }

                return $next($request);
            },
        );
    }

    public function testUnauthorizedRequestNeverReachesHandler(): void
    {
        $kernel = self::kernel();
        $handlerCalls = 0;

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

                        return new Response(200, [], 'secret');
                    },
                );
            },
            [self::tokenGuard()],
        );

        $response = $kernel->handle(
            new Request('GET', '/private/data'),
        );

        self::assertSame(401, $response->status);
        self::assertSame('Unauthorized', $response->body);
        self::assertSame(0, $handlerCalls);
    }

    public function testAuthorizedRequestReachesHandler(): void
    {
        $kernel = self::kernel();

        $kernel->router()->group(
            '/private',
            static function (Router $router): void {
                $router->get(
                    '/data',
                    static fn (Request $request): Response =>
                        new Response(200, [], 'secret'),
                );
            },
            [self::tokenGuard()],
        );

        $response = $kernel->handle(
            new Request(
                'GET',
                '/private/data',
                ['X-Access-Token' => 'valid'],
            ),
        );

        self::assertSame(200, $response->status);
        self::assertSame('secret', $response->body);
    }

    public function testNestedGuardsExecuteInExpectedOrder(): void
    {
        $kernel = self::kernel();
        $events = [];

        $outer = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'outer-before';

                try {
                    return $next($request);
                } finally {
                    $events[] = 'outer-after';
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
                    $events[] = 'inner-after';
                }
            },
        );

        $kernel->router()->group(
            '/admin',
            static function (Router $router) use (
                $inner,
                &$events,
            ): void {
                $router->group(
                    '/reports',
                    static function (Router $router) use (
                        &$events,
                    ): void {
                        $router->get(
                            '/daily',
                            static function (
                                Request $request,
                            ) use (&$events): Response {
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

        $response = $kernel->handle(
            new Request('GET', '/admin/reports/daily'),
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

    public function testOuterGuardBlocksInnerGuardAndHandler(): void
    {
        $kernel = self::kernel();
        $events = [];

        $outer = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'outer-denied';

                return new Response(403, [], 'Forbidden');
            },
        );

        $inner = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'inner';

                return $next($request);
            },
        );

        $kernel->router()->group(
            '/admin',
            static function (Router $router) use (
                $inner,
                &$events,
            ): void {
                $router->group(
                    '/reports',
                    static function (Router $router) use (
                        &$events,
                    ): void {
                        $router->get(
                            '/daily',
                            static function (
                                Request $request,
                            ) use (&$events): Response {
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

        $response = $kernel->handle(
            new Request('GET', '/admin/reports/daily'),
        );

        self::assertSame(403, $response->status);
        self::assertSame(['outer-denied'], $events);
    }

    public function testInnerGuardDenialStillUnwindsOuterMiddleware(): void
    {
        $kernel = self::kernel();
        $events = [];

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
                $events[] = 'inner-denied';

                return new Response(403, [], 'Forbidden');
            },
        );

        $kernel->router()->group(
            '/admin',
            static function (Router $router) use (
                $inner,
                &$events,
            ): void {
                $router->group(
                    '/reports',
                    static function (Router $router) use (
                        &$events,
                    ): void {
                        $router->get(
                            '/daily',
                            static function (
                                Request $request,
                            ) use (&$events): Response {
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

        $response = $kernel->handle(
            new Request('GET', '/admin/reports/daily'),
        );

        self::assertSame(403, $response->status);
        self::assertSame(
            [
                'outer-before',
                'inner-denied',
                'outer-after',
            ],
            $events,
        );
    }

    public function testPublicRoutesRemainIndependentOfProtectedGroups(): void
    {
        $kernel = self::kernel();

        $kernel->router()->group(
            '/private',
            static function (Router $router): void {
                $router->get(
                    '/data',
                    static fn (Request $request): Response =>
                        new Response(200, [], 'private'),
                );
            },
            [self::tokenGuard()],
        );

        $kernel->router()->get(
            '/public',
            static fn (Request $request): Response =>
                new Response(200, [], 'public'),
        );

        $private = $kernel->handle(
            new Request('GET', '/private/data'),
        );

        $public = $kernel->handle(
            new Request('GET', '/public'),
        );

        self::assertSame(401, $private->status);
        self::assertSame(200, $public->status);
        self::assertSame('public', $public->body);
    }

    public function testSecurityHeadersSurviveSuccessfulResponse(): void
    {
        $kernel = self::kernel();

        $securityHeaders = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ): Response {
                return $next($request)
                    ->withHeader('X-Content-Type-Options', 'nosniff')
                    ->withHeader('X-Frame-Options', 'DENY');
            },
        );

        $kernel->router()->group(
            '/api',
            static function (Router $router): void {
                $router->get(
                    '/data',
                    static fn (Request $request): Response =>
                        new Response(200, [], 'OK'),
                );
            },
            [$securityHeaders],
        );

        $response = $kernel->handle(
            new Request('GET', '/api/data'),
        );

        self::assertSame(200, $response->status);
        self::assertSame(
            'nosniff',
            $response->header('X-Content-Type-Options'),
        );
        self::assertSame(
            'DENY',
            $response->header('X-Frame-Options'),
        );
    }

    public function testOuterSecurityHeadersSurviveGuardDenial(): void
    {
        $kernel = self::kernel();

        $headers = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ): Response {
                return $next($request)
                    ->withHeader('X-Content-Type-Options', 'nosniff');
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
            [$headers, self::tokenGuard()],
        );

        $response = $kernel->handle(
            new Request('GET', '/private/data'),
        );

        self::assertSame(401, $response->status);
        self::assertSame(
            'nosniff',
            $response->header('X-Content-Type-Options'),
        );
    }

    public function testSecurityHeadersDoNotLeakToUnrelatedRoutes(): void
    {
        $kernel = self::kernel();

        $headers = self::middleware(
            static fn (
                Request $request,
                Closure $next,
            ): Response => $next($request)->withHeader(
                'X-Protected',
                'yes',
            ),
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
            [$headers],
        );

        $kernel->router()->get(
            '/public',
            static fn (Request $request): Response =>
                new Response(200),
        );

        $protected = $kernel->handle(
            new Request('GET', '/private/data'),
        );

        $public = $kernel->handle(
            new Request('GET', '/public'),
        );

        self::assertSame(
            'yes',
            $protected->header('X-Protected'),
        );
        self::assertNull($public->header('X-Protected'));
    }

    public function testGuardExceptionPropagatesAndKernelRecovers(): void
    {
        $kernel = self::kernel();

        $throwingGuard = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ): Response {
                throw new RuntimeException('Guard failure');
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
            [$throwingGuard],
        );

        $kernel->router()->get(
            '/health',
            static fn (Request $request): Response =>
                new Response(200, [], 'healthy'),
        );

        try {
            $kernel->handle(
                new Request('GET', '/private/data'),
            );

            self::fail('Expected guard exception.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Guard failure',
                $exception->getMessage(),
            );
        }

        $response = $kernel->handle(
            new Request('GET', '/health'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('healthy', $response->body);
    }

    public function testUnmatchedRoutesDoNotExecuteAuthorizationGuard(): void
    {
        $kernel = self::kernel();
        $guardCalls = 0;

        $guard = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$guardCalls): Response {
                ++$guardCalls;

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
            [$guard],
        );

        $missing = $kernel->handle(
            new Request('GET', '/private/missing'),
        );

        $wrongMethod = $kernel->handle(
            new Request('POST', '/private/data'),
        );

        self::assertSame(404, $missing->status);
        self::assertSame(405, $wrongMethod->status);
        self::assertSame('GET', $wrongMethod->header('Allow'));
        self::assertSame(0, $guardCalls);
    }

    public function testMiddlewareCanPassValidatedRequestCopy(): void
    {
        $kernel = self::kernel();

        $normalizer = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ): Response {
                $validated = $request->withHeader(
                    'X-Validated',
                    'yes',
                );

                return $next($validated);
            },
        );

        $kernel->router()->group(
            '/api',
            static function (Router $router): void {
                $router->get(
                    '/data',
                    static fn (Request $request): Response =>
                        new Response(
                            200,
                            [],
                            $request->header('X-Validated') ?? 'missing',
                        ),
                );
            },
            [$normalizer],
        );

        $original = new Request('GET', '/api/data');

        $response = $kernel->handle($original);

        self::assertSame(200, $response->status);
        self::assertSame('yes', $response->body);
        self::assertNull($original->header('X-Validated'));
    }

    public function testDeniedResponseDoesNotContaminateLaterSuccess(): void
    {
        $kernel = self::kernel();

        $kernel->router()->group(
            '/private',
            static function (Router $router): void {
                $router->get(
                    '/data',
                    static fn (Request $request): Response =>
                        new Response(200, [], 'secret'),
                );
            },
            [self::tokenGuard()],
        );

        $denied = $kernel->handle(
            new Request('GET', '/private/data'),
        );

        $allowed = $kernel->handle(
            new Request(
                'GET',
                '/private/data',
                ['X-Access-Token' => 'valid'],
            ),
        );

        self::assertSame(401, $denied->status);
        self::assertSame('Unauthorized', $denied->body);
        self::assertSame(200, $allowed->status);
        self::assertSame('secret', $allowed->body);
    }

    public function testProtectedRouteRegistrationCannotChangeDuringDispatch(): void
    {
        $kernel = self::kernel();
        $registrationRejected = false;

        $guard = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ) use (
                $kernel,
                &$registrationRejected,
            ): Response {
                try {
                    $kernel->router()->get(
                        '/injected',
                        static fn (Request $request): Response =>
                            new Response(200),
                    );
                } catch (LogicException $exception) {
                    $registrationRejected = true;
                }

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
            [$guard],
        );

        $response = $kernel->handle(
            new Request('GET', '/private/data'),
        );

        $injected = $kernel->handle(
            new Request('GET', '/injected'),
        );

        self::assertSame(200, $response->status);
        self::assertTrue($registrationRejected);
        self::assertSame(404, $injected->status);
        self::assertSame(1, $kernel->router()->count());
    }

    public function testAuthorizationIsReevaluatedForEveryRequest(): void
    {
        $kernel = self::kernel();
        $guardCalls = 0;
        $handlerCalls = 0;

        $guard = self::middleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$guardCalls): Response {
                ++$guardCalls;

                if ($request->header('X-Access-Token') !== 'valid') {
                    return new Response(401, [], 'Unauthorized');
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

                        return new Response(200, [], 'secret');
                    },
                );
            },
            [$guard],
        );

        $tokens = [
            null,
            'valid',
            'invalid',
            'valid',
            null,
        ];

        $expectedStatuses = [401, 200, 401, 200, 401];

        foreach ($tokens as $index => $token) {
            $headers = $token === null
                ? []
                : ['X-Access-Token' => $token];

            $response = $kernel->handle(
                new Request(
                    'GET',
                    '/private/data',
                    $headers,
                ),
            );

            self::assertSame(
                $expectedStatuses[$index],
                $response->status,
            );
        }

        self::assertSame(5, $guardCalls);
        self::assertSame(2, $handlerCalls);
    }

    public function testGlobalGuardRunsBeforeRouteGroupGuard(): void
    {
        $kernel = self::kernel();
        $events = [];

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

        self::assertSame(200, $response->status);
        self::assertSame(
            ['global', 'group', 'handler'],
            $events,
        );
    }
}
