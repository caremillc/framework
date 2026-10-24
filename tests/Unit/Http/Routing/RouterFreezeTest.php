<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Routing;

use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\Router;
use LogicException;
use PHPUnit\Framework\TestCase;

final class RouterFreezeTest extends TestCase
{
    public function testRouterStartsMutable(): void
    {
        $router = new Router();

        self::assertFalse($router->isFrozen());
    }

    public function testFreezeMarksRouterAsFrozen(): void
    {
        $router = new Router();

        $router->freeze();

        self::assertTrue($router->isFrozen());
    }

    public function testFreezeIsIdempotent(): void
    {
        $router = new Router();

        $router->freeze();
        $router->freeze();

        self::assertTrue($router->isFrozen());
    }

    public function testFrozenRouterRejectsRegistration(): void
    {
        $router = new Router();
        $router->freeze();

        $this->expectException(LogicException::class);

        $router->get(
            '/late',
            static fn (Request $request): Response =>
                new Response(200),
        );
    }

    public function testFrozenRouterPreservesExistingRoutes(): void
    {
        $router = new Router();

        $router->get(
            '/health',
            static fn (Request $request): Response =>
                new Response(200, body: 'healthy'),
        );

        $router->freeze();

        $response = $router->dispatch(
            new Request('GET', '/health'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('healthy', $response->body);
    }

    public function testFrozenRouterStillReturns404(): void
    {
        $router = new Router();
        $router->freeze();

        $response = $router->dispatch(
            new Request('GET', '/missing'),
        );

        self::assertSame(404, $response->status);
    }

    public function testFrozenRouterStillReturns405(): void
    {
        $router = new Router();

        $router->get(
            '/items',
            static fn (Request $request): Response =>
                new Response(200),
        );

        $router->freeze();

        $response = $router->dispatch(
            new Request('POST', '/items'),
        );

        self::assertSame(405, $response->status);
        self::assertSame('GET', $response->header('Allow'));
    }
}
