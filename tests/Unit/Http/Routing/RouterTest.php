<?php

declare (strict_types=1);

namespace Careminate\Tests\Unit\Http\Routing;

use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\Router;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RouterTest extends TestCase
{
    public function testDispatchesMatchingRoute(): void
    {
        $router = new Router();

        $router->get(
            '/health',
            static fn (Request $request): Response =>
            new Response(200, body: 'OK')
        );

        $response = $router->dispatch(
            new Request('GET', '/health')
        );

        self::assertSame(200, $response->status);
        self::assertSame('OK', $response->body);
    }

    public function testQueryStringDoesNotAffectMatching(): void
    {
        $router = new Router();

        $router->get(
            '/users',
            static fn (Request $request): Response =>
            new Response(
                200,
                body: $request->queryString()
            )
        );

        $response = $router->dispatch(
            new Request('GET', '/users?page=2')
        );

        self::assertSame(200, $response->status);
        self::assertSame('page=2', $response->body);
    }

    public function testUnknownPathReturns404(): void
    {
        $router = new Router();

        $response = $router->dispatch(
            new Request('GET', '/missing')
        );

        self::assertSame(404, $response->status);
        self::assertSame('Not Found', $response->body);
    }

    public function testWrongMethodReturns405WithAllowHeader(): void
    {
        $router = new Router();

        $router->post(
            '/items',
            static fn (Request $request): Response =>
            new Response(201)
        );

        $router->get(
            '/items',
            static fn (Request $request): Response =>
            new Response(200)
        );

        $response = $router->dispatch(
            new Request('DELETE', '/items')
        );

        self::assertSame(405, $response->status);
        self::assertSame(
            'GET, POST',
            $response->header('allow')
        );
    }

    public function testMethodMatchingIsCaseInsensitive(): void
    {
        $router = new Router();

        $router->add(
            'get',
            '/health',
            static fn (Request $request): Response =>
            new Response(200)
        );

        $response = $router->dispatch(
            new Request('GET', '/health')
        );

        self::assertSame(200, $response->status);
    }

    public function testDuplicateRouteIsRejected(): void
    {
        $router = new Router();

        $handler = static fn (Request $request): Response =>
        new Response(200);

        $router->get('/health', $handler);

        $this->expectException(LogicException::class);

        $router->add('get', '/health', $handler);
    }

    public function testDifferentMethodsMaySharePath(): void
    {
        $router = new Router();

        $router->get(
            '/items',
            static fn (Request $request): Response =>
            new Response(200)
        );

        $router->post(
            '/items',
            static fn (Request $request): Response =>
            new Response(201)
        );

        self::assertSame(2, $router->count());
    }

    public function testPathsAreCaseSensitive(): void
    {
        $router = new Router();

        $router->get(
            '/Health',
            static fn (Request $request): Response =>
            new Response(200)
        );

        $response = $router->dispatch(
            new Request('GET', '/health')
        );

        self::assertSame(404, $response->status);
    }

    public function testHandlerExceptionPropagates(): void
    {
        $router = new Router();

        $router->get(
            '/failure',
            static function (Request $request): Response {
                throw new RuntimeException(
                    'Handler failure'
                );
            }
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Handler failure');

        $router->dispatch(
            new Request('GET', '/failure')
        );
    }

    public function testEmptyRouterHasZeroRoutes(): void
    {
        $router = new Router();

        self::assertSame(0, $router->count());
    }
}
