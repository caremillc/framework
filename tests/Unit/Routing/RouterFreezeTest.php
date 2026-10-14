<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Routing;

use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;
use Flint\Routing\RouteConstraint;
use Flint\Routing\Router;
use LogicException;
use PHPUnit\Framework\TestCase;

final class RouterFreezeTest extends TestCase
{
    public function testRouterStartsOpenAndFreezeIsIdempotent(): void
    {
        $router = new Router();

        self::assertFalse($router->isFrozen());

        $router->freeze();
        $router->freeze();

        self::assertTrue($router->isFrozen());
        self::assertSame([], $router->describe());
    }

    public function testFrozenRouterStillMatchesAndGeneratesTargets(): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/hello/{name}',
            static fn (Request $request): Response =>
                ResponseFactory::text($request->path()),
            'greeting',
        );

        $router->freeze();

        $response = $router->handle(
            new Request(target: '/hello/Ada'),
        );

        self::assertSame(200, $response->status());
        self::assertSame('/hello/Ada', $response->body());

        self::assertSame(
            '/hello/Ada%20Lovelace?style=formal',
            $router->target(
                'greeting',
                ['name' => 'Ada Lovelace'],
                ['style' => 'formal'],
            ),
        );

        self::assertCount(1, $router->describe());
    }

    public function testRejectedRegistrationDoesNotChangeExistingRoutes(): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/health',
            static fn (Request $request): Response =>
                ResponseFactory::text('healthy'),
            'health',
        );

        $before = $router->describe();
        $router->freeze();

        try {
            $router->add(
                'POST',
                '/new',
                static fn (Request $request): Response =>
                    ResponseFactory::text('new'),
                'new',
            );

            self::fail('Frozen registration must throw.');
        } catch (LogicException $exception) {
            self::assertSame(
                'Routes cannot be registered after the router is frozen.',
                $exception->getMessage(),
            );
        }

        self::assertSame($before, $router->describe());
        self::assertSame('/health', $router->path('health'));

        self::assertSame(
            404,
            $router->handle(new Request(target: '/new'))->status(),
        );

        self::assertSame(
            'healthy',
            $router->handle(new Request(target: '/health'))->body(),
        );
    }

    public function testHandlerRegistrationIsRejectedAfterFreeze(): void
    {
        $router = new Router();
        $router->freeze();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Routes cannot be registered after the router is frozen.',
        );

        $router->addHandler(
            'GET',
            '/health',
            self::handler(),
            'health',
        );
    }

    public function testExistingGroupCannotRegisterAfterFreeze(): void
    {
        $router = new Router();
        $group = $router->group('/api', 'api.');

        $router->freeze();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Routes cannot be registered after the router is frozen.',
        );

        $group->addConstrainedHandler(
            'GET',
            '/items/{id}',
            self::handler(),
            ['id' => RouteConstraint::Digits],
            'items.show',
        );
    }

    public function testFreezingOneRouterDoesNotFreezeAnother(): void
    {
        $first = new Router();
        $second = new Router();

        $first->freeze();

        $second->addHandler(
            'GET',
            '/health',
            self::handler(),
            'health',
        );

        self::assertTrue($first->isFrozen());
        self::assertFalse($second->isFrozen());
        self::assertSame([], $first->describe());
        self::assertSame('/health', $second->path('health'));

        self::assertSame(
            200,
            $second->handle(new Request(target: '/health'))->status(),
        );
    }

    private static function handler(): RequestHandler
    {
        return new class () implements RequestHandler {
            public function handle(Request $request): Response
            {
                return ResponseFactory::text('ok');
            }
        };
    }
}
