<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Routing;

use Careminate\Foundation\Application;
use Careminate\Http\Kernel;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\Router;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RouterConflictHardeningTest extends TestCase
{
    private static function handler(string $body): \Closure
    {
        return static fn (Request $request): Response =>
            new Response(200, [], $body);
    }

    private static function dispatch(
        Router $router,
        string $method,
        string $target,
    ): Response {
        return $router->dispatch(
            new Request($method, $target),
        );
    }

    public function testExactRouteWinsOverDynamicRoute(): void
    {
        $router = new Router();

        $router->get('/users/{id}', self::handler('dynamic'));
        $router->get('/users/me', self::handler('exact'));

        self::assertSame(
            'exact',
            self::dispatch($router, 'GET', '/users/me')->body,
        );

        self::assertSame(
            'dynamic',
            self::dispatch($router, 'GET', '/users/42')->body,
        );
    }

    public function testMoreLiteralSegmentsWinRegardlessOfOrder(): void
    {
        $router = new Router();

        $router->get(
            '/{section}/{item}',
            self::handler('generic'),
        );

        $router->get(
            '/products/{item}',
            self::handler('products'),
        );

        self::assertSame(
            'products',
            self::dispatch(
                $router,
                'GET',
                '/products/123',
            )->body,
        );
    }

    public function testConstrainedPatternWinsOverGenericPattern(): void
    {
        $router = new Router();

        $router->get(
            '/items/{slug}',
            self::handler('generic'),
        );

        $router->get(
            '/items/{id}',
            self::handler('numeric'),
            ['id' => '[0-9]+'],
        );

        self::assertSame(
            'numeric',
            self::dispatch($router, 'GET', '/items/123')->body,
        );

        self::assertSame(
            'generic',
            self::dispatch($router, 'GET', '/items/widget')->body,
        );
    }

    public function testEqualSpecificityUsesRegistrationOrder(): void
    {
        $router = new Router();

        $router->get(
            '/{category}/list',
            self::handler('first'),
        );

        $router->get(
            '/products/{action}',
            self::handler('second'),
        );

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            self::assertSame(
                'first',
                self::dispatch(
                    $router,
                    'GET',
                    '/products/list',
                )->body,
            );
        }
    }

    public function testDuplicateMethodAndPathAreRejectedAtomically(): void
    {
        $router = new Router();

        $router->get('/health', self::handler('original'));

        try {
            $router->get(
                '/health',
                self::handler('replacement'),
            );

            self::fail('Expected duplicate route rejection.');
        } catch (LogicException $exception) {
            self::assertSame(
                'Route "GET /health" is already registered.',
                $exception->getMessage(),
            );
        }

        self::assertSame(1, $router->count());
        self::assertSame(
            'original',
            self::dispatch($router, 'GET', '/health')->body,
        );
    }

    public function testSamePathSupportsDifferentMethods(): void
    {
        $router = new Router();

        $router->get('/items', self::handler('read'));
        $router->post('/items', self::handler('create'));

        self::assertSame(2, $router->count());

        self::assertSame(
            'read',
            self::dispatch($router, 'GET', '/items')->body,
        );

        self::assertSame(
            'create',
            self::dispatch($router, 'POST', '/items')->body,
        );
    }

    public function testConflictingConstraintsAcrossMethodsAreRejected(): void
    {
        $router = new Router();

        $router->get(
            '/items/{id}',
            self::handler('read'),
            ['id' => '[0-9]+'],
        );

        try {
            $router->post(
                '/items/{id}',
                self::handler('write'),
                ['id' => '[a-z]+'],
            );

            self::fail('Expected constraint conflict.');
        } catch (LogicException $exception) {
            self::assertSame(
                'Conflicting constraints for route pattern "/items/{id}".',
                $exception->getMessage(),
            );
        }

        self::assertSame(1, $router->count());

        self::assertSame(
            405,
            self::dispatch($router, 'POST', '/items/123')->status,
        );
    }

    public function testEquivalentConstraintsAcrossMethodsAreAccepted(): void
    {
        $router = new Router();

        $router->get(
            '/items/{id}',
            self::handler('read'),
            ['id' => '[0-9]+'],
        );

        $router->post(
            '/items/{id}',
            self::handler('write'),
            ['id' => '[0-9]+'],
        );

        self::assertSame(2, $router->count());

        self::assertSame(
            'read',
            self::dispatch($router, 'GET', '/items/7')->body,
        );

        self::assertSame(
            'write',
            self::dispatch($router, 'POST', '/items/7')->body,
        );
    }

    public function testInvalidRoutePatternDoesNotRegisterRoute(): void
    {
        $router = new Router();

        try {
            $router->get(
                '/items/{id}/{id}',
                self::handler('invalid'),
            );

            self::fail('Expected duplicate parameter rejection.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Duplicate route parameter "id".',
                $exception->getMessage(),
            );
        }

        self::assertSame(0, $router->count());
        self::assertSame(
            404,
            self::dispatch(
                $router,
                'GET',
                '/items/1/2',
            )->status,
        );
    }

    public function testMethodNotAllowedCombinesAllowedMethods(): void
    {
        $router = new Router();

        $router->post('/items', self::handler('post'));
        $router->get('/items', self::handler('get'));

        $response = self::dispatch(
            $router,
            'PATCH',
            '/items',
        );

        self::assertSame(405, $response->status);
        self::assertSame('GET, POST', $response->header('Allow'));
        self::assertSame('Method Not Allowed', $response->body);
    }

    public function testMethodNotAllowedIncludesDynamicMatches(): void
    {
        $router = new Router();

        $router->get(
            '/items/{id}',
            self::handler('read'),
            ['id' => '[0-9]+'],
        );

        $router->post(
            '/items/{slug}',
            self::handler('write'),
        );

        $response = self::dispatch(
            $router,
            'DELETE',
            '/items/123',
        );

        self::assertSame(405, $response->status);
        self::assertSame('GET, POST', $response->header('Allow'));
    }

    public function testNonMatchingConstraintReturnsNotFound(): void
    {
        $router = new Router();

        $router->get(
            '/items/{id}',
            self::handler('found'),
            ['id' => '[0-9]+'],
        );

        $response = self::dispatch(
            $router,
            'GET',
            '/items/abc',
        );

        self::assertSame(404, $response->status);
        self::assertSame('Not Found', $response->body);
        self::assertNull($response->header('Allow'));
    }

    public function testDuplicateRouteNameIsRejectedWithoutMutation(): void
    {
        $router = new Router();

        $router->get(
            '/users/{id}',
            self::handler('user'),
            [],
            'users.show',
        );

        try {
            $router->get(
                '/profiles/{id}',
                self::handler('profile'),
                [],
                'users.show',
            );

            self::fail('Expected duplicate name rejection.');
        } catch (LogicException $exception) {
            self::assertSame(
                'Route name "users.show" is already registered.',
                $exception->getMessage(),
            );
        }

        self::assertSame(1, $router->count());
        self::assertSame(
            '/users/42',
            $router->url('users.show', ['id' => 42]),
        );
        self::assertSame(
            404,
            self::dispatch(
                $router,
                'GET',
                '/profiles/42',
            )->status,
        );
    }

    public function testInvalidRouteNameDoesNotRegisterRoute(): void
    {
        $router = new Router();

        foreach (['', 'invalid name', '9startsWithDigit'] as $name) {
            try {
                $router->get(
                    '/test',
                    self::handler('test'),
                    [],
                    $name,
                );

                self::fail('Expected invalid route name.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame(
                    'Invalid route name.',
                    $exception->getMessage(),
                );
            }
        }

        self::assertSame(0, $router->count());
    }

    public function testNamedRouteRejectsMissingAndExtraParameters(): void
    {
        $router = new Router();

        $router->get(
            '/users/{id}',
            self::handler('user'),
            [],
            'users.show',
        );

        try {
            $router->url('users.show');

            self::fail('Expected missing parameter rejection.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Missing route parameter "id".',
                $exception->getMessage(),
            );
        }

        try {
            $router->url(
                'users.show',
                ['id' => 1, 'extra' => 'value'],
            );

            self::fail('Expected extra parameter rejection.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Unknown route parameter "extra".',
                $exception->getMessage(),
            );
        }
    }

    public function testNamedRouteEnforcesConstraintsDuringGeneration(): void
    {
        $router = new Router();

        $router->get(
            '/users/{id}',
            self::handler('user'),
            ['id' => '[0-9]+'],
            'users.show',
        );

        self::assertSame(
            '/users/123',
            $router->url('users.show', ['id' => 123]),
        );

        try {
            $router->url(
                'users.show',
                ['id' => 'abc'],
            );

            self::fail('Expected constraint violation.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Route parameter "id" violates its constraint.',
                $exception->getMessage(),
            );
        }
    }

    public function testGroupStateIsRestoredAfterCallbackException(): void
    {
        $router = new Router();

        try {
            $router->group(
                '/api',
                static function (Router $router): void {
                    $router->get(
                        '/inside',
                        self::handler('inside'),
                    );

                    throw new RuntimeException('Group failed');
                },
            );

            self::fail('Expected group callback exception.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Group failed',
                $exception->getMessage(),
            );
        }

        $router->get(
            '/outside',
            self::handler('outside'),
        );

        self::assertSame(2, $router->count());

        self::assertSame(
            'inside',
            self::dispatch(
                $router,
                'GET',
                '/api/inside',
            )->body,
        );

        self::assertSame(
            'outside',
            self::dispatch(
                $router,
                'GET',
                '/outside',
            )->body,
        );

        self::assertSame(
            404,
            self::dispatch(
                $router,
                'GET',
                '/api/outside',
            )->status,
        );
    }

    public function testFrozenRouterRejectsRegistrationButStillDispatches(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/health',
            self::handler('healthy'),
        );

        $first = $kernel->handle(
            new Request('GET', '/health'),
        );

        self::assertSame(200, $first->status);
        self::assertTrue($kernel->router()->isFrozen());

        try {
            $kernel->router()->post(
                '/late',
                self::handler('late'),
            );

            self::fail('Expected frozen router rejection.');
        } catch (LogicException $exception) {
            self::assertSame(
                'Cannot register routes after the router is frozen.',
                $exception->getMessage(),
            );
        }

        self::assertSame(1, $kernel->router()->count());

        $second = $kernel->handle(
            new Request('GET', '/health'),
        );

        self::assertSame(200, $second->status);
        self::assertSame('healthy', $second->body);
    }
}
