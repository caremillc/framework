<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Routing;

use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\Router;
use LogicException;
use PHPUnit\Framework\TestCase;

final class RouteConstraintConsistencyTest extends TestCase
{
    public function testMatchingConstraintsAcrossMethodsAreAllowed(): void
    {
        $router = new Router();

        $handler = static fn (Request $request): Response =>
            new Response(200, [], 'matched');

        $router->get(
            '/users/{id}',
            $handler,
            ['id' => '[0-9]+'],
        );

        $router->post(
            '/users/{id}',
            $handler,
            ['id' => '[0-9]+'],
        );

        self::assertSame(2, $router->count());

        self::assertSame(
            200,
            $router->dispatch(
                new Request('GET', '/users/42'),
            )->status,
        );

        self::assertSame(
            200,
            $router->dispatch(
                new Request('POST', '/users/42'),
            )->status,
        );
    }

    public function testConflictingConstraintsAreRejected(): void
    {
        $router = new Router();

        $handler = static fn (Request $request): Response =>
            new Response();

        $router->get(
            '/users/{id}',
            $handler,
            ['id' => '[0-9]+'],
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Conflicting constraints',
        );

        $router->post(
            '/users/{id}',
            $handler,
            ['id' => '[a-z]+'],
        );
    }

    public function testRejectedRegistrationDoesNotModifyRouter(): void
    {
        $router = new Router();

        $handler = static fn (Request $request): Response =>
            new Response(200, [], 'original');

        $router->get(
            '/users/{id}',
            $handler,
            ['id' => '[0-9]+'],
        );

        try {
            $router->post(
                '/users/{id}',
                $handler,
                ['id' => '[a-z]+'],
            );
        } catch (LogicException) {
            // Expected conflict.
        }

        self::assertSame(1, $router->count());

        self::assertSame(
            'original',
            $router->dispatch(
                new Request('GET', '/users/42'),
            )->body,
        );

        self::assertSame(
            404,
            $router->dispatch(
                new Request('GET', '/users/alice'),
            )->status,
        );

        self::assertSame(
            405,
            $router->dispatch(
                new Request('POST', '/users/42'),
            )->status,
        );
    }

    public function testUnconstrainedAndConstrainedDefinitionsConflict(): void
    {
        $router = new Router();

        $handler = static fn (Request $request): Response =>
            new Response();

        $router->get('/users/{id}', $handler);

        $this->expectException(LogicException::class);

        $router->post(
            '/users/{id}',
            $handler,
            ['id' => '[0-9]+'],
        );
    }

    public function testEquivalentConstraintOrderingIsAccepted(): void
    {
        $router = new Router();

        $handler = static fn (Request $request): Response =>
            new Response();

        $router->get(
            '/projects/{projectId}/tasks/{taskId}',
            $handler,
            [
                'projectId' => '[0-9]+',
                'taskId' => '[a-z]+',
            ],
        );

        $router->post(
            '/projects/{projectId}/tasks/{taskId}',
            $handler,
            [
                'taskId' => '[a-z]+',
                'projectId' => '[0-9]+',
            ],
        );

        self::assertSame(2, $router->count());
    }
}
