<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Middleware;

use Careminate\Foundation\Application;
use Careminate\Http\Kernel;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\Router;
use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;

final class KernelMiddlewareIntegrationTest extends TestCase
{
    public function testKernelWithoutMiddlewareStillDispatchesRoutes(): void
    {
        $application = new Application();
        $router = new Router();

        $router->get(
            '/health',
            static fn (Request $request): Response =>
                new Response(200, [], 'healthy'),
        );

        $kernel = new Kernel($application, $router);

        $response = $kernel->handle(
            new Request('GET', '/health'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('healthy', $response->body);
        self::assertTrue($application->isBooted());
    }

    public function testMiddlewareWrapsRouterDispatch(): void
    {
        $application = new Application();
        $router = new Router();
        $events = [];

        $router->get(
            '/items',
            static function (Request $request) use (&$events): Response {
                $events[] = 'route';

                return new Response(200, [], 'items');
            },
        );

        $kernel = new Kernel($application, $router);

        $kernel->closureMiddleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'before';

                $response = $next($request);

                self::assertInstanceOf(Response::class, $response);

                $events[] = 'after';

                return $response;
            },
        );

        $response = $kernel->handle(
            new Request('GET', '/items'),
        );

        self::assertSame(
            ['before', 'route', 'after'],
            $events,
        );

        self::assertSame('items', $response->body);
    }

    public function testMiddlewareCanShortCircuitRouter(): void
    {
        $application = new Application();
        $router = new Router();
        $routeCalled = false;

        $router->get(
            '/private',
            static function (
                Request $request,
            ) use (&$routeCalled): Response {
                $routeCalled = true;

                return new Response(200, [], 'private');
            },
        );

        $kernel = new Kernel($application, $router);

        $kernel->closureMiddleware(
            static fn (
                Request $request,
                Closure $next,
            ): Response => new Response(403, [], 'Forbidden'),
        );

        $response = $kernel->handle(
            new Request('GET', '/private'),
        );

        self::assertSame(403, $response->status);
        self::assertSame('Forbidden', $response->body);
        self::assertFalse($routeCalled);
    }

    public function testMiddlewareCanTransformNotFoundResponse(): void
    {
        $application = new Application();
        $kernel = new Kernel($application, new Router());

        $kernel->closureMiddleware(
            static function (
                Request $request,
                Closure $next,
            ): Response {
                $response = $next($request);

                self::assertInstanceOf(Response::class, $response);

                return new Response(
                    $response->status,
                    $response->headers,
                    'wrapped: ' . $response->body,
                );
            },
        );

        $response = $kernel->handle(
            new Request('GET', '/missing'),
        );

        self::assertSame(404, $response->status);
        self::assertSame(
            'wrapped: Not Found',
            $response->body,
        );
    }

    public function testMiddlewareCanTransformMethodNotAllowedResponse(): void
    {
        $application = new Application();
        $router = new Router();

        $router->get(
            '/items',
            static fn (Request $request): Response =>
                new Response(200, [], 'items'),
        );

        $kernel = new Kernel($application, $router);

        $kernel->closureMiddleware(
            static function (
                Request $request,
                Closure $next,
            ): Response {
                $response = $next($request);

                self::assertInstanceOf(Response::class, $response);

                return new Response(
                    $response->status,
                    $response->headers,
                    'wrapped: ' . $response->body,
                );
            },
        );

        $response = $kernel->handle(
            new Request('POST', '/items'),
        );

        self::assertSame(405, $response->status);
        self::assertSame(
            'GET',
            $response->headers['allow'] ?? null,
        );
        self::assertSame(
            'wrapped: Method Not Allowed',
            $response->body,
        );
    }

    public function testMiddlewareRegistrationAfterBootIsRejected(): void
    {
        $application = new Application();
        $kernel = new Kernel($application);

        $kernel->handle(
            new Request('GET', '/missing'),
        );

        self::assertTrue($application->isBooted());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'HTTP middleware must be registered before application boot.',
        );

        $kernel->closureMiddleware(
            static fn (
                Request $request,
                Closure $next,
            ): Response => new Response(200),
        );
    }

    public function testKernelCanHandleMultipleRequests(): void
    {
        $application = new Application();
        $router = new Router();

        $router->get(
            '/items/{id}',
            static fn (Request $request): Response =>
                new Response(
                    200,
                    [],
                    $request->routeParameter('id') ?? '',
                ),
        );

        $kernel = new Kernel($application, $router);

        $kernel->closureMiddleware(
            static function (
                Request $request,
                Closure $next,
            ): Response {
                $response = $next($request);

                self::assertInstanceOf(Response::class, $response);

                return $response;
            },
        );

        $first = $kernel->handle(
            new Request('GET', '/items/10'),
        );

        $second = $kernel->handle(
            new Request('GET', '/items/20'),
        );

        self::assertSame('10', $first->body);
        self::assertSame('20', $second->body);
        self::assertTrue($application->isBooted());
    }
}
