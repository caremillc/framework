<?php

declare (strict_types=1);

namespace Careminate\Tests\Unit\Http\Routing;

use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\Route;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RouteTest extends TestCase
{
    public function testMethodIsNormalized(): void
    {
        $route = new Route(
            'get',
            '/health',
            static fn (Request $request): Response =>
            new Response(200)
        );

        self::assertSame('GET', $route->method);
        self::assertSame('/health', $route->path);
    }

    public function testHandlerReceivesRequest(): void
    {
        $request = new Request('GET', '/health');

        $route = new Route(
            'GET',
            '/health',
            static fn (Request $received): Response =>
            new Response(
                200,
                body: $received->path()
            )
        );

        $response = $route->run($request);

        self::assertSame('/health', $response->body);
    }

    public function testInvalidMethodIsRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        new Route(
            'GET BAD',
            '/',
            static fn (Request $request): Response =>
            new Response()
        );
    }

    public function testInvalidPathIsRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        new Route(
            'GET',
            '/users?page=1',
            static fn (Request $request): Response =>
            new Response()
        );
    }
}
