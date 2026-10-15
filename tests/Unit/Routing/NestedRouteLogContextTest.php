<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Routing;

use Flint\Http\Request;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;
use Flint\Routing\RouteLogContext;
use Flint\Routing\Router;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NestedRouteLogContextTest extends TestCase
{
    public function testNestedDispatchPreservesOuterRouteInLogContext(): void
    {
        $router = new Router();
        $context = new RouteLogContext();

        $router->add(
            'GET',
            '/inner',
            static function (Request $request): Response {
                $routeName = $request->attribute('route.name');

                self::assertIsString($routeName);

                return ResponseFactory::text($routeName);
            },
            'inner',
        );

        $router->add(
            'GET',
            '/outer',
            static function (Request $request) use ($router): Response {
                return $router->handle(
                    new Request(
                        target: '/inner',
                        attributes: [
                            'route.log_context' =>
                            $request->attribute('route.log_context'),
                        ],
                    ),
                );
            },
            'outer',
        );

        $router->freeze();

        $response = $router->handle(
            new Request(
                target: '/outer',
                attributes: ['route.log_context' => $context],
            ),
        );

        self::assertSame(200, $response->status());

        // The inner handler receives its own route metadata.
        self::assertSame('inner', $response->body());

        // The completion log retains the incoming request's selected route.
        self::assertSame('outer', $context->routeName());
    }

    public function testUnnamedOuterRouteRemainsUnnamedAfterNestedDispatch(): void
    {
        $router = new Router();
        $context = new RouteLogContext();

        $router->add(
            'GET',
            '/inner',
            static fn (Request $request): Response =>
            ResponseFactory::text('inner'),
            'inner',
        );

        $router->add(
            'GET',
            '/outer',
            static function (Request $request) use ($router): Response {
                return $router->handle(
                    new Request(
                        target: '/inner',
                        attributes: [
                            'route.log_context' =>
                            $request->attribute('route.log_context'),
                        ],
                    ),
                );
            },
        );

        $router->freeze();

        $response = $router->handle(
            new Request(
                target: '/outer',
                attributes: ['route.log_context' => $context],
            ),
        );

        self::assertSame('inner', $response->body());
        self::assertNull($context->routeName());
    }

    public function testNestedFailureRetainsOuterRouteAndOriginalException(): void
    {
        $router = new Router();
        $context = new RouteLogContext();
        $failure = new RuntimeException('Expected nested failure.');

        $router->add(
            'GET',
            '/inner',
            static function (Request $request) use ($failure): Response {
                throw $failure;
            },
            'inner.failure',
        );

        $router->add(
            'GET',
            '/outer',
            static function (Request $request) use ($router): Response {
                return $router->handle(
                    new Request(
                        target: '/inner',
                        attributes: [
                            'route.log_context' =>
                            $request->attribute('route.log_context'),
                        ],
                    ),
                );
            },
            'outer',
        );

        $router->freeze();

        $caught = null;

        try {
            $router->handle(
                new Request(
                    target: '/outer',
                    attributes: ['route.log_context' => $context],
                ),
            );
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        self::assertSame($failure, $caught);
        self::assertSame('outer', $context->routeName());
    }

    public function testSeparateRequestsHaveIndependentRouteSelections(): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/first',
            static fn (Request $request): Response =>
            ResponseFactory::text('first'),
            'first',
        );

        $router->add(
            'GET',
            '/second',
            static fn (Request $request): Response =>
            ResponseFactory::text('second'),
            'second',
        );

        $router->freeze();

        $firstContext = new RouteLogContext();
        $secondContext = new RouteLogContext();

        $firstResponse = $router->handle(
            new Request(
                target: '/first',
                attributes: ['route.log_context' => $firstContext],
            ),
        );

        $secondResponse = $router->handle(
            new Request(
                target: '/second',
                attributes: ['route.log_context' => $secondContext],
            ),
        );

        self::assertSame('first', $firstResponse->body());
        self::assertSame('second', $secondResponse->body());
        self::assertSame('first', $firstContext->routeName());
        self::assertSame('second', $secondContext->routeName());
    }
}
