<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Routing;

use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\Router;
use LogicException;
use PHPUnit\Framework\TestCase;

final class RegisteredRoutesTest extends TestCase
{
    public function testEmptyRouterHasEmptyReadOnlySnapshot(): void
    {
        $router = new Router();

        self::assertSame([], $router->registeredRoutes());
        self::assertSame(0, $router->count());
        self::assertFalse($router->isFrozen());
    }

    public function testSnapshotIsSortedAndKeepsMethodSpecificNames(): void
    {
        $router = new Router();
        $handler = self::handler();

        $router->get('/z', $handler, [], 'z.show');
        $router->post('/a', $handler, [], 'a.store');
        $router->get('/a', $handler, [], 'a.index');

        self::assertSame(
            [
                ['method' => 'GET', 'path' => '/a', 'name' => 'a.index'],
                ['method' => 'POST', 'path' => '/a', 'name' => 'a.store'],
                ['method' => 'GET', 'path' => '/z', 'name' => 'z.show'],
            ],
            $router->registeredRoutes(),
        );
    }

    public function testUnnamedRouteDoesNotBorrowOtherMethodName(): void
    {
        $router = new Router();

        $router->get(
            '/items',
            self::handler(),
            [],
            'items.index',
        );

        $router->post('/items', self::handler());

        self::assertSame(
            [
                [
                    'method' => 'GET',
                    'path' => '/items',
                    'name' => 'items.index',
                ],
                [
                    'method' => 'POST',
                    'path' => '/items',
                    'name' => null,
                ],
            ],
            $router->registeredRoutes(),
        );
    }

    public function testNestedGroupUsesResolvedPath(): void
    {
        $router = new Router();

        $router->group(
            '/api',
            static function (Router $router): void {
                $router->group(
                    '/v1',
                    static function (Router $router): void {
                        $router->get(
                            '/users/{id}',
                            self::handler(),
                            ['id' => '[0-9]+'],
                            'users.show',
                        );
                    },
                );
            },
        );

        self::assertSame(
            [
                [
                    'method' => 'GET',
                    'path' => '/api/v1/users/{id}',
                    'name' => 'users.show',
                ],
            ],
            $router->registeredRoutes(),
        );

        self::assertSame(
            '/api/v1/users/7',
            $router->url(
                'users.show',
                ['id' => 7],
            ),
        );
    }

    public function testSnapshotDoesNotExecuteHandlers(): void
    {
        $called = 0;
        $router = new Router();

        $router->get(
            '/health',
            static function (Request $request) use (&$called): Response {
                ++$called;

                return new Response(200);
            },
        );

        self::assertCount(1, $router->registeredRoutes());
        self::assertSame(0, $called);
        self::assertFalse($router->isFrozen());
    }

    public function testSnapshotCannotMutateRouterState(): void
    {
        $router = new Router();

        $router->get(
            '/a',
            self::handler(),
            [],
            'a.show',
        );

        $snapshot = $router->registeredRoutes();

        $snapshot[0]['path'] = '/modified';
        $snapshot[0]['name'] = 'modified';

        $snapshot[] = [
            'method' => 'POST',
            'path' => '/x',
            'name' => null,
        ];

        self::assertSame(
            [
                [
                    'method' => 'GET',
                    'path' => '/a',
                    'name' => 'a.show',
                ],
            ],
            $router->registeredRoutes(),
        );

        self::assertSame(1, $router->count());
    }

    public function testSnapshotAvailableAfterFreeze(): void
    {
        $router = new Router();

        $router->get('/a', self::handler());
        $router->freeze();

        self::assertTrue($router->isFrozen());

        self::assertSame(
            [
                [
                    'method' => 'GET',
                    'path' => '/a',
                    'name' => null,
                ],
            ],
            $router->registeredRoutes(),
        );

        self::assertTrue($router->isFrozen());
    }

    public function testDuplicateMethodDoesNotCorruptMetadata(): void
    {
        $router = new Router();

        $router->get(
            '/a',
            self::handler(),
            [],
            'a.first',
        );

        try {
            $router->get(
                '/a',
                self::handler(),
                [],
                'a.second',
            );

            self::fail('Duplicate method and path must fail.');
        } catch (LogicException) {
            self::assertSame(
                [
                    [
                        'method' => 'GET',
                        'path' => '/a',
                        'name' => 'a.first',
                    ],
                ],
                $router->registeredRoutes(),
            );

            self::assertFalse($router->hasName('a.second'));
        }
    }

    public function testDuplicateNameDoesNotCorruptMetadata(): void
    {
        $router = new Router();

        $router->get(
            '/a',
            self::handler(),
            [],
            'reserved',
        );

        try {
            $router->post(
                '/b',
                self::handler(),
                [],
                'reserved',
            );

            self::fail('Duplicate route name must fail.');
        } catch (LogicException) {
            self::assertSame(
                [
                    [
                        'method' => 'GET',
                        'path' => '/a',
                        'name' => 'reserved',
                    ],
                ],
                $router->registeredRoutes(),
            );

            self::assertSame(1, $router->count());
        }
    }

    public function testConflictingPatternConstraintDoesNotMutateMetadata(): void
    {
        $router = new Router();

        $router->get(
            '/a/{id}',
            self::handler(),
            ['id' => '[0-9]+'],
            'a.show',
        );

        try {
            $router->post(
                '/a/{id}',
                self::handler(),
                ['id' => '[a-z]+'],
                'a.store',
            );

            self::fail('Conflicting constraints must fail.');
        } catch (LogicException) {
            self::assertSame(
                [
                    [
                        'method' => 'GET',
                        'path' => '/a/{id}',
                        'name' => 'a.show',
                    ],
                ],
                $router->registeredRoutes(),
            );

            self::assertFalse($router->hasName('a.store'));
        }
    }

    public function testOnlyExplicitMethodsAreListed(): void
    {
        $router = new Router();

        $router->get('/health', self::handler());

        self::assertSame(
            [
                [
                    'method' => 'GET',
                    'path' => '/health',
                    'name' => null,
                ],
            ],
            $router->registeredRoutes(),
        );
    }

    public function testSnapshotContainsOnlyPublicMetadata(): void
    {
        $router = new Router();

        $router->get('/a', self::handler());

        self::assertSame(
            ['method', 'path', 'name'],
            array_keys(
                $router->registeredRoutes()[0],
            ),
        );

        self::assertCount(
            $router->count(),
            $router->registeredRoutes(),
        );
    }

    /**
     * @return \Closure(Request): Response
     */
    private static function handler(): \Closure
    {
        return static fn (Request $request): Response =>
            new Response(200);
    }
}
