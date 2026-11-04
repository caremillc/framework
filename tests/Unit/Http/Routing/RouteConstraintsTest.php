<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Routing;

use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\RoutePattern;
use Careminate\Http\Routing\Router;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RouteConstraintsTest extends TestCase
{
    public function testNumericConstraintMatches(): void
    {
        $pattern = new RoutePattern(
            '/users/{id}',
            ['id' => '[0-9]+'],
        );

        self::assertSame(
            ['id' => '42'],
            $pattern->match('/users/42'),
        );
        self::assertNull($pattern->match('/users/alice'));
    }

    public function testUnknownConstraintIsRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new RoutePattern(
            '/users/{id}',
            ['missing' => '[0-9]+'],
        );
    }

    public function testInvalidConstraintIsRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new RoutePattern(
            '/users/{id}',
            ['id' => '(.*)'],
        );
    }

    public function testStaticRoutePrecedence(): void
    {
        $router = new Router();

        $router->get(
            '/users/{id}',
            static fn (Request $request): Response =>
                new Response(200, [], 'dynamic'),
            ['id' => '[a-z]+'],
        );

        $router->get(
            '/users/new',
            static fn (Request $request): Response =>
                new Response(200, [], 'static'),
        );

        self::assertSame(
            'static',
            $router->dispatch(
                new Request('GET', '/users/new'),
            )->body,
        );
    }

    public function testConstrainedRoutePrecedence(): void
    {
        $router = new Router();

        $router->get(
            '/users/{value}',
            static fn (Request $request): Response =>
                new Response(200, [], 'generic'),
        );

        $router->get(
            '/users/{id}',
            static fn (Request $request): Response =>
                new Response(200, [], 'numeric'),
            ['id' => '[0-9]+'],
        );

        self::assertSame(
            'numeric',
            $router->dispatch(
                new Request('GET', '/users/42'),
            )->body,
        );
    }

    public function testLiteralSegmentPrecedence(): void
    {
        $router = new Router();

        $router->get(
            '/projects/{id}/{action}',
            static fn (Request $request): Response =>
                new Response(200, [], 'generic'),
        );

        $router->get(
            '/projects/{id}/settings',
            static fn (Request $request): Response =>
                new Response(200, [], 'specific'),
        );

        self::assertSame(
            'specific',
            $router->dispatch(
                new Request('GET', '/projects/42/settings'),
            )->body,
        );
    }

    public function testConstraintFailureReturns404(): void
    {
        $router = new Router();

        $router->get(
            '/users/{id}',
            static fn (Request $request): Response =>
                new Response(),
            ['id' => '[0-9]+'],
        );

        self::assertSame(
            404,
            $router->dispatch(
                new Request('GET', '/users/alice'),
            )->status,
        );
    }

    public function testConstraintFailureDoesNotProduce405(): void
    {
        $router = new Router();

        $router->post(
            '/users/{id}',
            static fn (Request $request): Response =>
                new Response(),
            ['id' => '[0-9]+'],
        );

        self::assertSame(
            404,
            $router->dispatch(
                new Request('GET', '/users/alice'),
            )->status,
        );
    }
}
