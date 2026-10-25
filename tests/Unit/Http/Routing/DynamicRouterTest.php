<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Routing;

use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\Router;
use LogicException;
use PHPUnit\Framework\TestCase;

final class DynamicRouterTest extends TestCase
{
    public function testSingleParameter(): void
    {
        $router = new Router();

        $router->get(
            '/users/{id}',
            static fn (Request $request): Response => new Response(
                200,
                [],
                $request->routeParameter('id') ?? '',
            ),
        );

        $response = $router->dispatch(
            new Request('GET', '/users/42'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('42', $response->body);
    }

    public function testMultipleParameters(): void
    {
        $router = new Router();

        $router->get(
            '/projects/{projectId}/tasks/{taskId}',
            static function (Request $request): Response {
                return new Response(
                    200,
                    [],
                    implode(':', $request->routeParameters()),
                );
            },
        );

        $response = $router->dispatch(
            new Request('GET', '/projects/7/tasks/19'),
        );

        self::assertSame('7:19', $response->body);
    }

    public function testStaticRouteTakesPrecedence(): void
    {
        $router = new Router();

        $router->get(
            '/users/{id}',
            static fn (Request $request): Response => new Response(
                200,
                [],
                'dynamic',
            ),
        );

        $router->get(
            '/users/new',
            static fn (Request $request): Response => new Response(
                200,
                [],
                'static',
            ),
        );

        $response = $router->dispatch(
            new Request('GET', '/users/new'),
        );

        self::assertSame('static', $response->body);
    }

    public function testDynamicRouteReturns404WhenMissingSegment(): void
    {
        $router = new Router();

        $router->get(
            '/users/{id}',
            static fn (Request $request): Response => new Response(),
        );

        $response = $router->dispatch(
            new Request('GET', '/users/'),
        );

        self::assertSame(404, $response->status);
    }

    public function testDynamicRouteReturns405ForWrongMethod(): void
    {
        $router = new Router();

        $router->get(
            '/users/{id}',
            static fn (Request $request): Response => new Response(),
        );

        $response = $router->dispatch(
            new Request('POST', '/users/42'),
        );

        self::assertSame(405, $response->status);
        self::assertSame('GET', $response->headers['allow']);
    }

    public function testDynamicRouteSupportsQueryString(): void
    {
        $router = new Router();

        $router->get(
            '/users/{id}',
            static fn (Request $request): Response => new Response(
                200,
                [],
                $request->routeParameter('id') ?? '',
            ),
        );

        $response = $router->dispatch(
            new Request('GET', '/users/42?tab=profile'),
        );

        self::assertSame('42', $response->body);
    }

    public function testDuplicateDynamicRouteIsRejected(): void
    {
        $router = new Router();

        $handler = static fn (Request $request): Response => new Response();

        $router->get('/users/{id}', $handler);

        $this->expectException(LogicException::class);

        $router->get('/users/{id}', $handler);
    }

    public function testFrozenRouterRejectsDynamicRegistration(): void
    {
        $router = new Router();
        $router->freeze();

        $this->expectException(LogicException::class);

        $router->get(
            '/users/{id}',
            static fn (Request $request): Response => new Response(),
        );
    }

    public function testDynamicRoutesAreIncludedInCount(): void
    {
        $router = new Router();

        $handler = static fn (Request $request): Response => new Response();

        $router->get('/users/{id}', $handler);
        $router->post('/users/{id}', $handler);
        $router->get('/health', $handler);

        self::assertSame(3, $router->count());
    }

    public function testExactAndDynamicAllowedMethodsAreCombined(): void
    {
        $router = new Router();

        $handler = static fn (Request $request): Response => new Response();

        $router->get('/users/new', $handler);
        $router->post('/users/{id}', $handler);

        $response = $router->dispatch(
            new Request('DELETE', '/users/new'),
        );

        self::assertSame(405, $response->status);
        self::assertSame(
            'GET, POST',
            $response->headers['allow'],
        );
    }
}
