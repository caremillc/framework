<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Routing;

use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\Router;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RouteGroupTest extends TestCase
{
    public function testGroupPrefixAppliesToRegisteredRoutes(): void
    {
        $router = new Router();

        $router->group('/api', static function (Router $router): void {
            $router->get(
                '/health',
                static fn (Request $request): Response =>
                    new Response(200, [], 'OK'),
            );
        });

        self::assertSame(
            200,
            $router->dispatch(new Request('GET', '/api/health'))->status,
        );

        self::assertSame(
            404,
            $router->dispatch(new Request('GET', '/health'))->status,
        );
    }

    public function testNestedGroupsComposePrefixes(): void
    {
        $router = new Router();

        $router->group('/api', static function (Router $router): void {
            $router->group('/v1', static function (Router $router): void {
                $router->get(
                    '/users/{id}',
                    static fn (Request $request): Response =>
                        new Response(
                            200,
                            [],
                            $request->routeParameter('id') ?? '',
                        ),
                );
            });
        });

        $response = $router->dispatch(
            new Request('GET', '/api/v1/users/42'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('42', $response->body);
    }

    public function testNamedRouteUsesResolvedGroupPath(): void
    {
        $router = new Router();

        $router->group('/api', static function (Router $router): void {
            $router->get(
                '/users/{id}',
                static fn (Request $request): Response =>
                    new Response(200),
                ['id' => '[0-9]+'],
                'api.users.show',
            );
        });

        self::assertSame(
            '/api/users/42',
            $router->url('api.users.show', ['id' => 42]),
        );
    }

    public function testGroupPrefixDoesNotLeak(): void
    {
        $router = new Router();

        $handler = static fn (Request $request): Response =>
            new Response(200);

        $router->group('/api', static function (Router $router) use ($handler): void {
            $router->get('/inside', $handler);
        });

        $router->get('/outside', $handler);

        self::assertSame(
            200,
            $router->dispatch(new Request('GET', '/outside'))->status,
        );

        self::assertSame(
            404,
            $router->dispatch(new Request('GET', '/api/outside'))->status,
        );
    }

    public function testGroupPrefixRestoredAfterException(): void
    {
        $router = new Router();

        try {
            $router->group('/api', static function (Router $router): void {
                throw new RuntimeException('Group registration failed.');
            });
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Group registration failed.',
                $exception->getMessage(),
            );
        }

        $router->get(
            '/health',
            static fn (Request $request): Response =>
                new Response(200),
        );

        self::assertSame(
            200,
            $router->dispatch(new Request('GET', '/health'))->status,
        );
    }

    public function testEmptyPrefixDoesNotChangePath(): void
    {
        $router = new Router();

        $router->group('', static function (Router $router): void {
            $router->get(
                '/health',
                static fn (Request $request): Response =>
                    new Response(200),
            );
        });

        self::assertSame(
            200,
            $router->dispatch(new Request('GET', '/health'))->status,
        );
    }

    public function testRootGroupPrefixDoesNotChangePath(): void
    {
        $router = new Router();

        $router->group('/', static function (Router $router): void {
            $router->get(
                '/health',
                static fn (Request $request): Response =>
                    new Response(200),
            );
        });

        self::assertSame(
            200,
            $router->dispatch(new Request('GET', '/health'))->status,
        );
    }

    public function testGroupRootRoutePreservesTrailingSlash(): void
    {
        $router = new Router();

        $router->group('/api', static function (Router $router): void {
            $router->get(
                '/',
                static fn (Request $request): Response =>
                    new Response(200),
            );
        });

        self::assertSame(
            200,
            $router->dispatch(new Request('GET', '/api/'))->status,
        );

        self::assertSame(
            404,
            $router->dispatch(new Request('GET', '/api'))->status,
        );
    }

    public function testRejectsRelativeGroupPrefix(): void
    {
        $router = new Router();

        $this->expectException(InvalidArgumentException::class);

        $router->group(
            'api',
            static function (Router $router): void {
            },
        );
    }

    public function testRejectsTrailingSlashInGroupPrefix(): void
    {
        $router = new Router();

        $this->expectException(InvalidArgumentException::class);

        $router->group(
            '/api/',
            static function (Router $router): void {
            },
        );
    }

    public function testRejectsGroupRegistrationAfterFreeze(): void
    {
        $router = new Router();
        $router->freeze();

        $this->expectException(LogicException::class);

        $router->group(
            '/api',
            static function (Router $router): void {
            },
        );
    }

    public function testDuplicateResolvedRouteIsRejected(): void
    {
        $router = new Router();

        $handler = static fn (Request $request): Response =>
            new Response(200);

        $router->group('/api', static function (Router $router) use ($handler): void {
            $router->get('/health', $handler);
        });

        $this->expectException(LogicException::class);

        $router->get('/api/health', $handler);
    }
}
