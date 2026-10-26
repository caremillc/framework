<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Routing;

use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\Router;
use PHPUnit\Framework\TestCase;

final class RoutePrecedenceTest extends TestCase
{
    public function testFirstRegisteredRouteWinsEqualSpecificity(): void
    {
        $router = new Router();

        $router->get(
            '/articles/{slug}',
            static fn (Request $request): Response =>
                new Response(200, [], 'first'),
        );

        $router->get(
            '/articles/{name}',
            static fn (Request $request): Response =>
                new Response(200, [], 'second'),
        );

        $response = $router->dispatch(
            new Request('GET', '/articles/example'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('first', $response->body);
    }

    public function testReversingRegistrationOrderChangesWinner(): void
    {
        $router = new Router();

        $router->get(
            '/articles/{name}',
            static fn (Request $request): Response =>
                new Response(200, [], 'second'),
        );

        $router->get(
            '/articles/{slug}',
            static fn (Request $request): Response =>
                new Response(200, [], 'first'),
        );

        $response = $router->dispatch(
            new Request('GET', '/articles/example'),
        );

        self::assertSame('second', $response->body);
    }

    public function testLiteralSpecificityOverridesRegistrationOrder(): void
    {
        $router = new Router();

        $router->get(
            '/projects/{project}/tasks/{task}',
            static fn (Request $request): Response =>
                new Response(200, [], 'generic'),
        );

        $router->get(
            '/projects/{project}/tasks/active',
            static fn (Request $request): Response =>
                new Response(200, [], 'specific'),
        );

        $response = $router->dispatch(
            new Request('GET', '/projects/7/tasks/active'),
        );

        self::assertSame('specific', $response->body);
    }

    public function testConstraintSpecificityOverridesRegistrationOrder(): void
    {
        $router = new Router();

        $router->get(
            '/users/{id}',
            static fn (Request $request): Response =>
                new Response(200, [], 'generic'),
        );

        $router->get(
            '/users/{userId}',
            static fn (Request $request): Response =>
                new Response(200, [], 'numeric'),
            ['userId' => '[0-9]+'],
        );

        $response = $router->dispatch(
            new Request('GET', '/users/42'),
        );

        self::assertSame('numeric', $response->body);
    }

    public function testRequestedMethodCanSelectLessSpecificPattern(): void
    {
        $router = new Router();

        $router->post(
            '/users/{id}',
            static fn (Request $request): Response =>
                new Response(200, [], 'post'),
            ['id' => '[0-9]+'],
        );

        $router->get(
            '/users/{name}',
            static fn (Request $request): Response =>
                new Response(200, [], 'get'),
        );

        $response = $router->dispatch(
            new Request('GET', '/users/42'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('get', $response->body);
    }

    public function testAllowedMethodsIncludeOverlappingPatterns(): void
    {
        $router = new Router();

        $router->get(
            '/items/{id}',
            static fn (Request $request): Response =>
                new Response(),
            ['id' => '[0-9]+'],
        );

        $router->post(
            '/items/{slug}',
            static fn (Request $request): Response =>
                new Response(),
        );

        $response = $router->dispatch(
            new Request('DELETE', '/items/42'),
        );

        self::assertSame(405, $response->status);
        self::assertSame(
            'GET, POST',
            $response->headers['allow'] ?? null,
        );
    }

    public function testEqualSpecificityUsesOriginalRegistrationOrder(): void
    {
        $router = new Router();

        $router->get(
            '/records/{first}',
            static fn (Request $request): Response =>
                new Response(200, [], 'first'),
        );

        $router->get(
            '/records/{second}',
            static fn (Request $request): Response =>
                new Response(200, [], 'second'),
        );

        $router->get(
            '/records/{third}',
            static fn (Request $request): Response =>
                new Response(200, [], 'third'),
        );

        for ($iteration = 0; $iteration < 5; ++$iteration) {
            $response = $router->dispatch(
                new Request('GET', '/records/example'),
            );

            self::assertSame('first', $response->body);
        }
    }
}
