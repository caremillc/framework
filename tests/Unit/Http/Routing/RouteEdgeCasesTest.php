<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Routing;

use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\RoutePattern;
use Careminate\Http\Routing\Router;
use PHPUnit\Framework\TestCase;

final class RouteEdgeCasesTest extends TestCase
{
    public function testTrailingSlashDoesNotMatchDynamicRoute(): void
    {
        $pattern = new RoutePattern('/users/{id}');

        self::assertNull(
            $pattern->match('/users/42/'),
        );
    }

    public function testTrailingSlashIsDistinctForStaticRoutes(): void
    {
        $router = new Router();

        $router->get(
            '/users/42',
            static fn (Request $request): Response =>
                new Response(200, [], 'without-slash'),
        );

        $response = $router->dispatch(
            new Request('GET', '/users/42/'),
        );

        self::assertSame(404, $response->status);
    }

    public function testEncodedSlashRemainsWithinOneParameter(): void
    {
        $pattern = new RoutePattern('/files/{name}');

        self::assertSame(
            ['name' => 'a%2Fb'],
            $pattern->match('/files/a%2Fb'),
        );
    }

    public function testLiteralSlashCannotCrossParameterBoundary(): void
    {
        $pattern = new RoutePattern('/files/{name}');

        self::assertNull(
            $pattern->match('/files/a/b'),
        );
    }

    public function testEncodedSpaceRemainsEncoded(): void
    {
        $pattern = new RoutePattern('/files/{name}');

        self::assertSame(
            ['name' => 'my%20file'],
            $pattern->match('/files/my%20file'),
        );
    }

    public function testMethodMismatchReturns405(): void
    {
        $router = new Router();

        $router->get(
            '/users/{id}',
            static fn (Request $request): Response =>
                new Response(200, [], 'get'),
        );

        $response = $router->dispatch(
            new Request('POST', '/users/42'),
        );

        self::assertSame(405, $response->status);

        self::assertSame(
            'GET',
            $response->headers['allow'] ?? null,
        );
    }

    public function testUnknownPathReturns404(): void
    {
        $router = new Router();

        $router->get(
            '/users/{id}',
            static fn (Request $request): Response =>
                new Response(200, [], 'found'),
        );

        $response = $router->dispatch(
            new Request('GET', '/projects/42'),
        );

        self::assertSame(404, $response->status);
    }

    public function testParametersDoNotLeakBetweenRequests(): void
    {
        $router = new Router();

        $router->get(
            '/users/{id}',
            static fn (Request $request): Response =>
                new Response(
                    200,
                    [],
                    $request->routeParameter('id') ?? '',
                ),
        );

        $first = $router->dispatch(
            new Request('GET', '/users/10'),
        );

        $second = $router->dispatch(
            new Request('GET', '/users/20'),
        );

        self::assertSame('10', $first->body);
        self::assertSame('20', $second->body);
    }

    public function testStaticRouteOverridesDynamicRoute(): void
    {
        $router = new Router();

        $router->get(
            '/users/{id}',
            static fn (Request $request): Response =>
                new Response(200, [], 'dynamic'),
        );

        $router->get(
            '/users/me',
            static fn (Request $request): Response =>
                new Response(200, [], 'static'),
        );

        $response = $router->dispatch(
            new Request('GET', '/users/me'),
        );

        self::assertSame('static', $response->body);
    }

    public function testConstrainedRouteRejectsEncodedCharacters(): void
    {
        $pattern = new RoutePattern(
            '/users/{id}',
            ['id' => '[0-9]+'],
        );

        self::assertNull(
            $pattern->match('/users/%34%32'),
        );

        self::assertSame(
            ['id' => '42'],
            $pattern->match('/users/42'),
        );
    }
}
