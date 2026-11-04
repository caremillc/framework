<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Routing;

use Careminate\Foundation\Application;
use Careminate\Http\Kernel;
use Careminate\Http\Request;
use Careminate\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpMethodInteractionMatrixTest extends TestCase
{
    /**
     * @return iterable<string, array{
     *     list<string>,
     *     string,
     *     int,
     *     ?string,
     *     ?string,
     *     string
     * }>
     */
    public static function methodCases(): iterable
    {
        yield 'GET: GET' => [
            ['GET'],
            'GET',
            200,
            null,
            'GET',
            'GET',
        ];

        yield 'GET: HEAD fallback' => [
            ['GET'],
            'HEAD',
            200,
            null,
            'GET',
            '',
        ];

        yield 'GET: OPTIONS automatic' => [
            ['GET'],
            'OPTIONS',
            204,
            'GET, HEAD, OPTIONS',
            null,
            '',
        ];

        yield 'GET: POST mismatch' => [
            ['GET'],
            'POST',
            405,
            'GET, HEAD, OPTIONS',
            null,
            'Method Not Allowed',
        ];

        yield 'POST: POST' => [
            ['POST'],
            'POST',
            200,
            null,
            'POST',
            'POST',
        ];

        yield 'POST: HEAD mismatch' => [
            ['POST'],
            'HEAD',
            405,
            'OPTIONS, POST',
            null,
            '',
        ];

        yield 'POST: GET mismatch' => [
            ['POST'],
            'GET',
            405,
            'OPTIONS, POST',
            null,
            'Method Not Allowed',
        ];

        yield 'POST: OPTIONS automatic' => [
            ['POST'],
            'OPTIONS',
            204,
            'OPTIONS, POST',
            null,
            '',
        ];

        yield 'HEAD: explicit' => [
            ['HEAD'],
            'HEAD',
            200,
            null,
            'HEAD',
            '',
        ];

        yield 'HEAD: GET mismatch' => [
            ['HEAD'],
            'GET',
            405,
            'HEAD, OPTIONS',
            null,
            'Method Not Allowed',
        ];

        yield 'HEAD: OPTIONS automatic' => [
            ['HEAD'],
            'OPTIONS',
            204,
            'HEAD, OPTIONS',
            null,
            '',
        ];

        yield 'OPTIONS: explicit' => [
            ['OPTIONS'],
            'OPTIONS',
            200,
            null,
            'OPTIONS',
            'OPTIONS',
        ];

        yield 'OPTIONS: GET mismatch' => [
            ['OPTIONS'],
            'GET',
            405,
            'OPTIONS',
            null,
            'Method Not Allowed',
        ];

        yield 'OPTIONS: HEAD mismatch' => [
            ['OPTIONS'],
            'HEAD',
            405,
            'OPTIONS',
            null,
            '',
        ];

        yield 'GET POST: HEAD fallback' => [
            ['GET', 'POST'],
            'HEAD',
            200,
            null,
            'GET',
            '',
        ];

        yield 'GET POST: OPTIONS automatic' => [
            ['GET', 'POST'],
            'OPTIONS',
            204,
            'GET, HEAD, OPTIONS, POST',
            null,
            '',
        ];

        yield 'GET POST: DELETE mismatch' => [
            ['GET', 'POST'],
            'DELETE',
            405,
            'GET, HEAD, OPTIONS, POST',
            null,
            'Method Not Allowed',
        ];

        yield 'GET HEAD: explicit HEAD wins' => [
            ['GET', 'HEAD'],
            'HEAD',
            200,
            null,
            'HEAD',
            '',
        ];

        yield 'GET HEAD: OPTIONS automatic' => [
            ['GET', 'HEAD'],
            'OPTIONS',
            204,
            'GET, HEAD, OPTIONS',
            null,
            '',
        ];

        yield 'GET OPTIONS: explicit OPTIONS wins' => [
            ['GET', 'OPTIONS'],
            'OPTIONS',
            200,
            null,
            'OPTIONS',
            'OPTIONS',
        ];

        yield 'PATCH: PATCH' => [
            ['PATCH'],
            'PATCH',
            200,
            null,
            'PATCH',
            'PATCH',
        ];

        yield 'PATCH: OPTIONS automatic' => [
            ['PATCH'],
            'OPTIONS',
            204,
            'OPTIONS, PATCH',
            null,
            '',
        ];

        yield 'PATCH: GET mismatch' => [
            ['PATCH'],
            'GET',
            405,
            'OPTIONS, PATCH',
            null,
            'Method Not Allowed',
        ];

        yield 'no routes: GET' => [
            [],
            'GET',
            404,
            null,
            null,
            'Not Found',
        ];

        yield 'no routes: HEAD' => [
            [],
            'HEAD',
            404,
            null,
            null,
            '',
        ];

        yield 'no routes: OPTIONS' => [
            [],
            'OPTIONS',
            404,
            null,
            null,
            'Not Found',
        ];
    }

    /**
     * @param list<string> $registeredMethods
     */
    #[DataProvider('methodCases')]
    public function testMethodResponseMatrix(
        array $registeredMethods,
        string $requestMethod,
        int $expectedStatus,
        ?string $expectedAllow,
        ?string $expectedHandler,
        string $expectedBody,
    ): void {
        $kernel = new Kernel(new Application());

        foreach ($registeredMethods as $method) {
            $kernel->router()->add(
                $method,
                '/resource',
                static fn (Request $request): Response =>
                    new Response(
                        200,
                        ['X-Handler' => $method],
                        $method,
                    ),
            );
        }

        $response = $kernel->handle(
            new Request(
                $requestMethod,
                '/resource',
            ),
        );

        self::assertSame(
            $expectedStatus,
            $response->status,
        );

        self::assertSame(
            $expectedAllow,
            $response->header('Allow'),
        );

        self::assertSame(
            $expectedHandler,
            $response->header('X-Handler'),
        );

        self::assertSame(
            $expectedBody,
            $response->body,
        );
    }

    public function testMethodDiscoveryCombinesExactAndMatchingDynamicPatterns(): void
    {
        $kernel = new Kernel(new Application());

        $router = $kernel->router();

        $router->get(
            '/items/active',
            self::handler('exact'),
        );

        $router->post(
            '/items/{slug}',
            self::handler('dynamic-post'),
        );

        $router->add(
            'PATCH',
            '/items/{id}',
            self::handler('dynamic-patch'),
            ['id' => '[0-9]+'],
        );

        $active = $kernel->handle(
            new Request(
                'OPTIONS',
                '/items/active',
            ),
        );

        $numeric = $kernel->handle(
            new Request(
                'OPTIONS',
                '/items/42',
            ),
        );

        $mismatch = $kernel->handle(
            new Request(
                'DELETE',
                '/items/active',
            ),
        );

        self::assertSame(
            204,
            $active->status,
        );

        self::assertSame(
            'GET, HEAD, OPTIONS, POST',
            $active->header('Allow'),
        );

        self::assertSame(
            204,
            $numeric->status,
        );

        self::assertSame(
            'OPTIONS, PATCH, POST',
            $numeric->header('Allow'),
        );

        self::assertSame(
            405,
            $mismatch->status,
        );

        self::assertSame(
            'GET, HEAD, OPTIONS, POST',
            $mismatch->header('Allow'),
        );
    }

    public function testExplicitOptionsDynamicRouteOverridesAutomaticExactOptions(): void
    {
        $kernel = new Kernel(new Application());

        $router = $kernel->router();

        $router->get(
            '/items/active',
            self::handler('get'),
        );

        $router->add(
            'OPTIONS',
            '/items/{slug}',
            static fn (Request $request): Response =>
                new Response(
                    200,
                    [
                        'X-Slug' =>
                            $request->routeParameter('slug') ?? '',
                    ],
                    'explicit-options',
                ),
        );

        $response = $kernel->handle(
            new Request(
                'OPTIONS',
                '/items/active',
            ),
        );

        self::assertSame(
            200,
            $response->status,
        );

        self::assertSame(
            'explicit-options',
            $response->body,
        );

        self::assertSame(
            'active',
            $response->header('X-Slug'),
        );

        self::assertNull(
            $response->header('Allow'),
        );
    }

    public function testExactOptionsBeatsOverlappingDynamicOptions(): void
    {
        $kernel = new Kernel(new Application());

        $router = $kernel->router();

        $router->add(
            'OPTIONS',
            '/items/{slug}',
            self::handler('dynamic'),
        );

        $router->add(
            'OPTIONS',
            '/items/active',
            self::handler('exact'),
        );

        $response = $kernel->handle(
            new Request(
                'OPTIONS',
                '/items/active',
            ),
        );

        self::assertSame(
            200,
            $response->status,
        );

        self::assertSame(
            'exact',
            $response->body,
        );
    }

    public function testHeadFallbackPrefersExactGetToDynamicGet(): void
    {
        $kernel = new Kernel(new Application());

        $router = $kernel->router();

        $router->get(
            '/items/{slug}',
            self::handler('dynamic'),
        );

        $router->get(
            '/items/active',
            static fn (Request $request): Response =>
                new Response(
                    201,
                    ['X-Winner' => 'exact'],
                    'exact',
                ),
        );

        $response = $kernel->handle(
            new Request(
                'HEAD',
                '/items/active',
            ),
        );

        self::assertSame(
            201,
            $response->status,
        );

        self::assertSame(
            'exact',
            $response->header('X-Winner'),
        );

        self::assertSame(
            '',
            $response->body,
        );
    }

    public function testHeadFallbackUsesMostConstrainedDynamicGet(): void
    {
        $kernel = new Kernel(new Application());

        $router = $kernel->router();

        $router->get(
            '/users/{slug}',
            self::handler('generic'),
        );

        $router->get(
            '/users/{id}',
            static fn (Request $request): Response =>
                new Response(
                    200,
                    [
                        'X-Method' => $request->method,
                        'X-Id' =>
                            $request->routeParameter('id') ?? '',
                    ],
                    'specific',
                ),
            ['id' => '[0-9]+'],
        );

        $response = $kernel->handle(
            new Request(
                'HEAD',
                '/users/42',
            ),
        );

        self::assertSame(
            200,
            $response->status,
        );

        self::assertSame(
            'HEAD',
            $response->header('X-Method'),
        );

        self::assertSame(
            '42',
            $response->header('X-Id'),
        );

        self::assertSame(
            '',
            $response->body,
        );
    }

    public function testAllowMethodsAreSortedRegardlessOfRegistrationOrder(): void
    {
        $registrationOrders = [
            ['PATCH', 'POST', 'GET'],
            ['GET', 'POST', 'PATCH'],
        ];

        foreach ($registrationOrders as $methods) {
            $kernel = new Kernel(new Application());

            foreach ($methods as $method) {
                $kernel->router()->add(
                    $method,
                    '/resource',
                    self::handler($method),
                );
            }

            $response = $kernel->handle(
                new Request(
                    'DELETE',
                    '/resource',
                ),
            );

            self::assertSame(
                405,
                $response->status,
            );

            self::assertSame(
                'GET, HEAD, OPTIONS, PATCH, POST',
                $response->header('Allow'),
            );
        }
    }

    public function testNonmatchingConstraintIs404Not405OrAutomaticOptions(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/users/{id}',
            self::handler('numeric'),
            ['id' => '[0-9]+'],
        );

        foreach (['HEAD', 'OPTIONS', 'DELETE'] as $method) {
            $response = $kernel->handle(
                new Request(
                    $method,
                    '/users/not-a-number',
                ),
            );

            self::assertSame(
                404,
                $response->status,
            );

            self::assertNull(
                $response->header('Allow'),
            );

            self::assertSame(
                $method === 'HEAD' ? '' : 'Not Found',
                $response->body,
            );
        }
    }

    public function testAutomaticOptionsUsesPathWithoutQueryString(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/resource',
            self::handler('get'),
        );

        $response = $kernel->handle(
            new Request(
                'OPTIONS',
                '/resource?format=json',
            ),
        );

        self::assertSame(
            204,
            $response->status,
        );

        self::assertSame(
            'GET, HEAD, OPTIONS',
            $response->header('Allow'),
        );

        self::assertSame(
            '',
            $response->body,
        );
    }

    public function testAutomaticMethodsDoNotCreateSyntheticRoutes(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/resource',
            self::handler('get'),
        );

        $kernel->handle(
            new Request(
                'OPTIONS',
                '/resource',
            ),
        );

        $kernel->handle(
            new Request(
                'HEAD',
                '/resource',
            ),
        );

        self::assertSame(
            1,
            $kernel->router()->count(),
        );

        self::assertTrue(
            $kernel->router()->isFrozen(),
        );
    }


    public function testHeadRemovesBodyAfterGlobalMiddlewareTransformsIt(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/resource',
            self::handler('get'),
        );

        $kernel->closureMiddleware(
            /**
             * @param \Closure(Request): Response $next
             */
            static function (
                Request $request,
                \Closure $next,
            ): Response {
                /** @var Response $response */
                $response = $next($request);

                return $response
                    ->withBody('body from outer middleware')
                    ->withHeader(
                        'X-Middleware',
                        'applied',
                    );
            },
        );

        $response = $kernel->handle(
            new Request(
                'HEAD',
                '/resource',
            ),
        );

        self::assertSame(
            200,
            $response->status,
        );

        self::assertSame(
            'applied',
            $response->header('X-Middleware'),
        );

        self::assertSame(
            '',
            $response->body,
        );
    }


    /**
     * @return \Closure(Request): Response
     */
    private static function handler(string $body): \Closure
    {
        return static fn (Request $request): Response =>
            new Response(
                200,
                [],
                $body,
            );
    }
}
