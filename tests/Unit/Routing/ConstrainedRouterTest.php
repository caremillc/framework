<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Routing;

use Closure;
use Flint\Container\Container;
use Flint\Http\ContainerRequestHandler;
use Flint\Http\Middleware;
use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;
use Flint\Routing\RouteConstraint;
use Flint\Routing\Router;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class ConstrainedRouterTest extends TestCase
{
    public function testMismatchDoesNotResolveTheHandler(): void
    {
        $container = new Container();
        $created = 0;

        $container->bind(
            'handler',
            static function (ContainerInterface $services) use (
                &$created,
            ): RequestHandler {
                ++$created;

                return self::handler(
                    static fn (Request $request): Response =>
                        ResponseFactory::text('Matched'),
                );
            },
        );

        $router = new Router();

        $router->addConstrainedHandler(
            'GET',
            '/items/{id}',
            new ContainerRequestHandler($container, 'handler'),
            ['id' => RouteConstraint::Digits],
            'items.show',
        );

        self::assertSame(
            404,
            $router->handle(new Request('GET', '/items/abc'))->status(),
        );

        self::assertSame(
            404,
            $router->handle(new Request('POST', '/items/abc'))->status(),
        );

        self::assertSame(0, $created);

        self::assertSame(
            'Matched',
            $router->handle(new Request('GET', '/items/123'))->body(),
        );

        self::assertSame(1, $created);

        self::assertSame(
            '/items/123',
            $router->path('items.show', ['id' => '123']),
        );

        $this->expectException(InvalidArgumentException::class);

        $router->path('items.show', ['id' => 'abc']);
    }

    public function testAllowIncludesOnlyRoutesWhoseConstraintsMatch(): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/values/{value}',
            static fn (Request $request): Response =>
                ResponseFactory::text('GET'),
            constraints: ['value' => RouteConstraint::Digits],
        );

        $router->addConstrainedHandler(
            'POST',
            '/values/{value}',
            self::handler(
                static fn (Request $request): Response =>
                    ResponseFactory::text('POST'),
            ),
            ['value' => RouteConstraint::Slug],
        );

        $numeric = $router->handle(
            new Request('DELETE', '/values/123'),
        );

        self::assertSame(405, $numeric->status());

        self::assertSame(
            ['GET, HEAD, POST'],
            $numeric->headers()->get('Allow'),
        );

        $slug = $router->handle(
            new Request('GET', '/values/Ada'),
        );

        self::assertSame(405, $slug->status());
        self::assertSame(['POST'], $slug->headers()->get('Allow'));

        self::assertSame(
            404,
            $router->handle(new Request('GET', '/values/a_b'))->status(),
        );
    }

    public function testHeadFallbackRespectsConstraints(): void
    {
        $router = new Router();

        $router->addConstrainedHandler(
            'GET',
            '/items/{id}',
            self::handler(
                static fn (Request $request): Response =>
                    ResponseFactory::text($request->method()),
            ),
            ['id' => RouteConstraint::Digits],
        );

        $response = $router->handle(
            new Request('HEAD', '/items/123'),
        );

        self::assertSame(200, $response->status());
        self::assertSame('HEAD', $response->body());

        self::assertSame(
            404,
            $router->handle(new Request('HEAD', '/items/abc'))->status(),
        );
    }

    public function testGroupPrefixConstraintsRunBeforeMiddleware(): void
    {
        $router = new Router();
        $entered = 0;

        $group = $router
            ->group(
                '/teams/{team}',
                'teams.',
                self::middleware(
                    static function (
                        Request $request,
                        RequestHandler $next,
                    ) use (&$entered): Response {
                        ++$entered;

                        return $next->handle($request);
                    },
                ),
            )
            ->group('/items', 'items.');

        $group->addConstrainedHandler(
            'GET',
            '/{id}',
            self::handler(
                static fn (Request $request): Response =>
                    ResponseFactory::json(
                        $request->attribute('route.parameters'),
                    ),
            ),
            [
                'team' => RouteConstraint::Slug,
                'id' => RouteConstraint::Digits,
            ],
            'show',
        );

        self::assertSame(
            '{"team":"core","id":"123"}',
            $router->handle(
                new Request('GET', '/teams/core/items/123'),
            )->body(),
        );

        self::assertSame(
            404,
            $router->handle(
                new Request('GET', '/teams/bad_team/items/123'),
            )->status(),
        );

        self::assertSame(
            404,
            $router->handle(
                new Request('GET', '/teams/core/items/abc'),
            )->status(),
        );

        self::assertSame(1, $entered);

        self::assertSame(
            '/teams/core/items/123',
            $router->path(
                'teams.items.show',
                ['team' => 'core', 'id' => '123'],
            ),
        );

        $this->expectException(InvalidArgumentException::class);

        $router->path(
            'teams.items.show',
            ['team' => 'bad_team', 'id' => '123'],
        );
    }

    public function testConstraintsDoNotPermitDuplicateRouteShapes(): void
    {
        $router = new Router();

        $router->addConstrainedHandler(
            'GET',
            '/values/{id}',
            self::handler(
                static fn (Request $request): Response =>
                    ResponseFactory::text('Digits'),
            ),
            ['id' => RouteConstraint::Digits],
        );

        try {
            $router->addConstrainedHandler(
                'GET',
                '/values/{slug}',
                self::handler(
                    static fn (Request $request): Response =>
                        ResponseFactory::text('Slug'),
                ),
                ['slug' => RouteConstraint::Slug],
            );

            self::fail('Expected duplicate route registration to fail.');
        } catch (InvalidArgumentException) {
            self::assertSame(
                'Digits',
                $router->handle(new Request('GET', '/values/123'))->body(),
            );

            self::assertSame(
                404,
                $router->handle(new Request('GET', '/values/Ada'))->status(),
            );
        }
    }

    public function testInvalidConstraintsDoNotReserveTheRoute(): void
    {
        $router = new Router();

        $handler = self::handler(
            static fn (Request $request): Response =>
                ResponseFactory::text('Ready'),
        );

        try {
            $router->addConstrainedHandler(
                'GET',
                '/items/{id}',
                $handler,
                ['missing' => RouteConstraint::Digits],
                'items.show',
            );

            self::fail('Expected constraint validation to fail.');
        } catch (InvalidArgumentException) {
            $router->addConstrainedHandler(
                'GET',
                '/items/{id}',
                $handler,
                ['id' => RouteConstraint::Digits],
                'items.show',
            );
        }

        self::assertSame(
            'Ready',
            $router->handle(new Request('GET', '/items/123'))->body(),
        );
    }

    /**
     * @param Closure(Request): Response $callback
     */
    private static function handler(Closure $callback): RequestHandler
    {
        return new class ($callback) implements RequestHandler {
            /**
             * @param Closure(Request): Response $callback
             */
            public function __construct(
                private readonly Closure $callback,
            ) {
            }

            public function handle(Request $request): Response
            {
                return ($this->callback)($request);
            }
        };
    }

    /**
     * @param Closure(Request, RequestHandler): Response $callback
     */
    private static function middleware(Closure $callback): Middleware
    {
        return new class ($callback) implements Middleware {
            /**
             * @param Closure(Request, RequestHandler): Response $callback
             */
            public function __construct(
                private readonly Closure $callback,
            ) {
            }

            public function process(
                Request $request,
                RequestHandler $next,
            ): Response {
                return ($this->callback)($request, $next);
            }
        };
    }
}
