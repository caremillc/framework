<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Routing;

use Flint\Http\Headers;
use Flint\Http\Request;
use Flint\Http\Response;
use Flint\Routing\Router;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ParameterizedRouterTest extends TestCase
{
    public function testParametersAreAddedWithoutMutatingTheOriginalRequest(): void
    {
        $router = new Router();
        $received = null;
        $response = new Response(body: 'Matched');

        $router->add(
            'POST',
            '/teams/{team}/users/{user}',
            static function (Request $request) use (&$received, $response): Response {
                $received = $request;

                return $response;
            },
        );

        $headers = new Headers()->with('Content-Type', 'text/plain');

        $original = new Request(
            method: 'POST',
            target: '/teams/core/users/42?active=true',
            headers: $headers,
            body: 'Payload',
            attributes: ['request.id' => 'retained'],
        );

        self::assertSame($response, $router->handle($original));
        self::assertInstanceOf(Request::class, $received);
        self::assertNotSame($original, $received);
        self::assertFalse($original->hasAttribute('route.parameters'));
        self::assertSame(
            ['team' => 'core', 'user' => '42'],
            $received->attribute('route.parameters'),
        );
        self::assertSame('retained', $received->attribute('request.id'));
        self::assertSame('active=true', $received->queryString());
        self::assertSame($headers, $received->headers());
        self::assertSame('Payload', $received->body());
    }

    public function testLiteralPathTakesPriorityEvenWhenItsMethodIsUnsupported(): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/users/{id}',
            static fn (Request $request): Response => new Response(body: 'Dynamic'),
        );

        $router->add(
            'POST',
            '/users/new',
            static fn (Request $request): Response => new Response(body: 'Literal'),
        );

        $response = $router->handle(new Request(target: '/users/new'));

        self::assertSame(405, $response->status());
        self::assertSame(['POST'], $response->headers()->get('Allow'));
    }

    public function testExplicitHeadWinsOverEarlierGetFallback(): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/{group}/{id}',
            static fn (Request $request): Response => new Response(body: 'GET'),
        );

        $router->add(
            'HEAD',
            '/users/{id}',
            static fn (Request $request): Response => new Response(body: 'HEAD'),
        );

        $response = $router->handle(
            new Request(method: 'HEAD', target: '/users/42'),
        );

        self::assertSame('HEAD', $response->body());
    }

    public function testHeadFallbackPreservesMethodAndParameters(): void
    {
        $router = new Router();
        $received = null;

        $router->add(
            'GET',
            '/users/{id}',
            static function (Request $request) use (&$received): Response {
                $received = $request;

                return new Response(body: 'Representation');
            },
        );

        $response = $router->handle(
            new Request(method: 'HEAD', target: '/users/42'),
        );

        self::assertSame('Representation', $response->body());
        self::assertInstanceOf(Request::class, $received);
        self::assertSame('HEAD', $received->method());
        self::assertSame(['id' => '42'], $received->attribute('route.parameters'));
    }

    public function testAllowCombinesMethodsFromMatchingPatterns(): void
    {
        $router = new Router();

        $router->add(
            'POST',
            '/{group}/{id}',
            static fn (Request $request): Response => new Response(),
        );

        $router->add(
            'GET',
            '/users/{id}',
            static fn (Request $request): Response => new Response(),
        );

        $response = $router->handle(
            new Request(method: 'DELETE', target: '/users/42'),
        );

        self::assertSame(405, $response->status());
        self::assertSame(
            ['GET, HEAD, POST'],
            $response->headers()->get('Allow'),
        );
    }

    public function testEquivalentPatternsForTheSameMethodAreRejected(): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/users/{id}',
            static fn (Request $request): Response => new Response(),
        );

        $this->expectException(InvalidArgumentException::class);

        $router->add(
            'GET',
            '/users/{name}',
            static fn (Request $request): Response => new Response(),
        );
    }

    public function testDifferentMethodsRetainTheirOwnParameterNames(): void
    {
        $router = new Router();
        $captured = [];

        $router->add(
            'GET',
            '/users/{id}',
            static function (Request $request) use (&$captured): Response {
                $captured[] = $request->attribute('route.parameters');

                return new Response();
            },
        );

        $router->add(
            'POST',
            '/users/{name}',
            static function (Request $request) use (&$captured): Response {
                $captured[] = $request->attribute('route.parameters');

                return new Response();
            },
        );

        $router->handle(new Request(target: '/users/42'));
        $router->handle(new Request(method: 'POST', target: '/users/alice'));

        self::assertSame(
            [['id' => '42'], ['name' => 'alice']],
            $captured,
        );
    }

    public function testFirstMatchingHandlerForTheMethodWins(): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/{group}/{id}',
            static fn (Request $request): Response => new Response(body: 'First'),
        );

        $router->add(
            'GET',
            '/users/{id}',
            static fn (Request $request): Response => new Response(body: 'Second'),
        );

        self::assertSame(
            'First',
            $router->handle(new Request(target: '/users/42'))->body(),
        );
    }

    public function testUnmatchedParameterizedPathReturnsNotFound(): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/users/{id}',
            static fn (Request $request): Response => new Response(),
        );

        $response = $router->handle(new Request(target: '/users/'));

        self::assertSame(404, $response->status());
        self::assertSame([], $response->headers()->get('Allow'));
    }
}
