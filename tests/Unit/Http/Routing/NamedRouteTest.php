<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Routing;

use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\Router;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class NamedRouteTest extends TestCase
{
    public function testGeneratesStaticNamedRoute(): void
    {
        $router = new Router();

        $router->get(
            '/health',
            static fn (Request $request): Response => new Response(200),
            [],
            'health',
        );

        self::assertTrue($router->hasName('health'));
        self::assertSame('/health', $router->url('health'));
    }

    public function testGeneratesDynamicNamedRoute(): void
    {
        $router = new Router();

        $router->get(
            '/users/{id}',
            static fn (Request $request): Response => new Response(200),
            [],
            'users.show',
        );

        self::assertSame(
            '/users/42',
            $router->url('users.show', ['id' => 42]),
        );
    }

    public function testEncodesDynamicParameters(): void
    {
        $router = new Router();

        $router->get(
            '/search/{term}',
            static fn (Request $request): Response => new Response(200),
            [],
            'search',
        );

        self::assertSame(
            '/search/hello%20world',
            $router->url('search', ['term' => 'hello world']),
        );
    }

    public function testRejectsDuplicateNames(): void
    {
        $router = new Router();

        $handler = static fn (Request $request): Response =>
            new Response(200);

        $router->get('/first', $handler, [], 'duplicate');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Route name "duplicate" is already registered.',
        );

        $router->get('/second', $handler, [], 'duplicate');
    }

    public function testRejectsUnknownRouteName(): void
    {
        $router = new Router();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Unknown route name "missing".',
        );

        $router->url('missing');
    }

    public function testRejectsMissingParameter(): void
    {
        $router = new Router();

        $router->get(
            '/users/{id}',
            static fn (Request $request): Response => new Response(200),
            [],
            'users.show',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Missing route parameter "id".',
        );

        $router->url('users.show');
    }

    public function testRejectsUnknownParameter(): void
    {
        $router = new Router();

        $router->get(
            '/users/{id}',
            static fn (Request $request): Response => new Response(200),
            [],
            'users.show',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Unknown route parameter "extra".',
        );

        $router->url('users.show', [
            'id' => '42',
            'extra' => 'unused',
        ]);
    }

    public function testRejectsConstraintViolation(): void
    {
        $router = new Router();

        $router->get(
            '/users/{id}',
            static fn (Request $request): Response => new Response(200),
            ['id' => '[0-9]+'],
            'users.show',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Route parameter "id" violates its constraint.',
        );

        $router->url('users.show', ['id' => 'abc']);
    }

    public function testRejectsPathSeparators(): void
    {
        $router = new Router();

        $router->get(
            '/files/{name}',
            static fn (Request $request): Response => new Response(200),
            [],
            'files.show',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Invalid route parameter "name".',
        );

        $router->url('files.show', ['name' => 'a/b']);
    }

    public function testNamedRoutesStillDispatchNormally(): void
    {
        $router = new Router();

        $router->get(
            '/users/{id}',
            static fn (Request $request): Response =>
                new Response(
                    200,
                    [],
                    $request->routeParameter('id') ?? '',
                ),
            [],
            'users.show',
        );

        $response = $router->dispatch(
            new Request('GET', '/users/42'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('42', $response->body);
    }

    public function testCanGenerateUrlsAfterRouterIsFrozen(): void
    {
        $router = new Router();

        $router->get(
            '/users/{id}',
            static fn (Request $request): Response => new Response(200),
            [],
            'users.show',
        );

        $router->freeze();

        self::assertSame(
            '/users/42',
            $router->url('users.show', ['id' => 42]),
        );
    }

    public function testFailedNamedRegistrationDoesNotMutateRoutes(): void
    {
        $router = new Router();

        $handler = static fn (Request $request): Response =>
            new Response(200);

        $router->get('/first', $handler, [], 'shared');

        try {
            $router->get('/second', $handler, [], 'shared');
            self::fail('Expected duplicate route name exception.');
        } catch (LogicException $exception) {
            self::assertSame(
                'Route name "shared" is already registered.',
                $exception->getMessage(),
            );
        }

        self::assertSame(1, $router->count());
        self::assertFalse($router->hasName('second'));
    }
}
