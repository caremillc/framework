<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Routing;

use Careminate\Foundation\Application;
use Careminate\Http\Kernel;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\Router;
use Closure;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class RouterMethodConflictMatrixTest extends TestCase
{
    public function testDuplicateMethodIsRejectedAfterNormalization(): void
    {
        $router = new Router();

        $router->get(
            '/resource',
            self::handler('original'),
        );

        try {
            $router->add(
                'get',
                '/resource',
                self::handler('duplicate'),
            );

            self::fail(
                'Expected duplicate GET route to be rejected.',
            );
        } catch (LogicException) {
            self::assertSame(1, $router->count());
        }

        $response = $router->dispatch(
            new Request('GET', '/resource'),
        );

        self::assertSame('original', $response->body);
    }

    public function testDuplicateNameAcrossMethodsDoesNotReserveTheMethod(): void
    {
        $router = new Router();

        $router->get(
            '/records/{id}',
            self::handler('get'),
            ['id' => '[0-9]+'],
            'records.show',
        );

        try {
            $router->add(
                'HEAD',
                '/records/{id}',
                self::handler('head'),
                ['id' => '[0-9]+'],
                'records.show',
            );

            self::fail(
                'Expected duplicate route name to be rejected.',
            );
        } catch (LogicException) {
            self::assertSame(1, $router->count());

            self::assertSame(
                '/records/42',
                $router->url(
                    'records.show',
                    ['id' => 42],
                ),
            );
        }

        $router->add(
            'HEAD',
            '/records/{id}',
            self::handler('head'),
            ['id' => '[0-9]+'],
            'records.head',
        );

        self::assertSame(2, $router->count());

        self::assertTrue(
            $router->hasName('records.head'),
        );
    }

    public function testInvalidNameDoesNotCreatePartialRoute(): void
    {
        $router = new Router();

        try {
            $router->get(
                '/health',
                self::handler('bad'),
                [],
                'invalid name',
            );

            self::fail(
                'Expected invalid route name to be rejected.',
            );
        } catch (InvalidArgumentException) {
            self::assertSame(0, $router->count());
        }

        $router->get(
            '/health',
            self::handler('good'),
            [],
            'health',
        );

        self::assertSame(1, $router->count());

        self::assertSame(
            '/health',
            $router->url('health'),
        );
    }

    public function testInvalidConstraintDoesNotCreatePartialRouteOrName(): void
    {
        $router = new Router();

        try {
            $router->get(
                '/users/{id}',
                self::handler('bad'),
                ['missing' => '[0-9]+'],
                'users.invalid',
            );

            self::fail(
                'Expected invalid constraint to be rejected.',
            );
        } catch (InvalidArgumentException) {
            self::assertSame(0, $router->count());

            self::assertFalse(
                $router->hasName('users.invalid'),
            );
        }

        $router->get(
            '/users/{id}',
            self::handler('valid'),
            ['id' => '[0-9]+'],
            'users.show',
        );

        self::assertSame(
            '/users/42',
            $router->url(
                'users.show',
                ['id' => 42],
            ),
        );
    }

    public function testConflictingConstraintDoesNotReserveOtherMethod(): void
    {
        $router = new Router();

        $router->get(
            '/users/{id}',
            self::handler('get'),
            ['id' => '[0-9]+'],
        );

        try {
            $router->post(
                '/users/{id}',
                self::handler('bad-post'),
                ['id' => '[a-z]+'],
            );

            self::fail(
                'Expected conflicting constraints to be rejected.',
            );
        } catch (LogicException) {
            self::assertSame(1, $router->count());
        }

        $router->post(
            '/users/{id}',
            self::handler('post'),
            ['id' => '[0-9]+'],
        );

        self::assertSame(2, $router->count());

        $response = $router->dispatch(
            new Request('POST', '/users/42'),
        );

        self::assertSame('post', $response->body);
    }

    public function testIdenticalConstraintsPermitDifferentMethods(): void
    {
        $router = new Router();

        $constraints = [
            'id' => '[0-9]+',
        ];

        $router->get(
            '/items/{id}',
            self::handler('get'),
            $constraints,
        );

        $router->post(
            '/items/{id}',
            self::handler('post'),
            $constraints,
        );

        $router->add(
            'PATCH',
            '/items/{id}',
            self::handler('patch'),
            $constraints,
        );

        self::assertSame(3, $router->count());

        self::assertSame(
            'get',
            $router->dispatch(
                new Request('GET', '/items/7'),
            )->body,
        );

        self::assertSame(
            'post',
            $router->dispatch(
                new Request('POST', '/items/7'),
            )->body,
        );

        self::assertSame(
            'patch',
            $router->dispatch(
                new Request('PATCH', '/items/7'),
            )->body,
        );
    }

    public function testEqualSpecificityAmbiguousPatternsUseRegistrationOrder(): void
    {
        $router = new Router();

        $router->get(
            '/articles/{slug}',
            self::handler('first'),
        );

        $router->get(
            '/articles/{name}',
            self::handler('second'),
        );

        self::assertSame(2, $router->count());

        $response = $router->dispatch(
            new Request('GET', '/articles/example'),
        );

        self::assertSame('first', $response->body);
    }

    public function testOverlappingDynamicPatternsSelectRequestedMethod(): void
    {
        $router = new Router();

        $router->post(
            '/users/{id}',
            self::handler('post'),
            ['id' => '[0-9]+'],
        );

        $router->get(
            '/users/{name}',
            self::handler('get'),
        );

        self::assertSame(
            'get',
            $router->dispatch(
                new Request('GET', '/users/42'),
            )->body,
        );

        self::assertSame(
            'post',
            $router->dispatch(
                new Request('POST', '/users/42'),
            )->body,
        );

        $response = $router->dispatch(
            new Request('DELETE', '/users/42'),
        );

        self::assertSame(405, $response->status);

        self::assertSame(
            'GET, HEAD, OPTIONS, POST',
            $response->header('Allow'),
        );
    }

    public function testExplicitHeadRouteWinsOverGetFallback(): void
    {
        $kernel = new Kernel(new Application());

        $router = $kernel->router();

        $router->get(
            '/resource',
            self::handler('get-body'),
        );

        $router->add(
            'HEAD',
            '/resource',
            static fn (Request $request): Response =>
                new Response(
                    202,
                    ['X-Handler' => 'explicit-head'],
                    'head-body',
                ),
        );

        $head = $kernel->handle(
            new Request('HEAD', '/resource'),
        );

        $get = $kernel->handle(
            new Request('GET', '/resource'),
        );

        self::assertSame(2, $router->count());

        self::assertSame(202, $head->status);

        self::assertSame(
            'explicit-head',
            $head->header('X-Handler'),
        );

        self::assertSame('', $head->body);

        self::assertSame('get-body', $get->body);
    }

    public function testExplicitOptionsRouteWinsOverAutomaticResponse(): void
    {
        $kernel = new Kernel(new Application());

        $router = $kernel->router();

        $router->get(
            '/resource',
            self::handler('get-body'),
        );

        $router->add(
            'OPTIONS',
            '/resource',
            self::handler('manual-options'),
        );

        $response = $kernel->handle(
            new Request('OPTIONS', '/resource'),
        );

        self::assertSame(200, $response->status);

        self::assertSame(
            'manual-options',
            $response->body,
        );

        self::assertSame(2, $router->count());
    }

    public function testExplicitDynamicHeadOutranksStaticGetFallback(): void
    {
        $kernel = new Kernel(new Application());

        $router = $kernel->router();

        $router->get(
            '/catalog/featured',
            self::handler('static-get'),
        );

        $router->add(
            'HEAD',
            '/catalog/{slug}',
            static fn (Request $request): Response =>
                new Response(
                    202,
                    [
                        'X-Slug' =>
                            $request->routeParameter('slug') ?? '',
                    ],
                    'dynamic-head',
                ),
        );

        $head = $kernel->handle(
            new Request('HEAD', '/catalog/featured'),
        );

        $get = $kernel->handle(
            new Request('GET', '/catalog/featured'),
        );

        self::assertSame(202, $head->status);

        self::assertSame(
            'featured',
            $head->header('X-Slug'),
        );

        self::assertSame('', $head->body);

        self::assertSame(
            'static-get',
            $get->body,
        );
    }

    public function testAllowUnionIsSortedWithoutDuplicateMethods(): void
    {
        $router = new Router();

        $router->get(
            '/items/active',
            self::handler('exact'),
        );

        $router->post(
            '/items/{slug}',
            self::handler('dynamic-post'),
        );

        $router->add(
            'PATCH',
            '/items/{id}',
            self::handler('dynamic-patch'),
            ['id' => '[0-9]+'],
        );

        $active = $router->dispatch(
            new Request('DELETE', '/items/active'),
        );

        $numeric = $router->dispatch(
            new Request('DELETE', '/items/42'),
        );

        self::assertSame(405, $active->status);

        self::assertSame(
            'GET, HEAD, OPTIONS, POST',
            $active->header('Allow'),
        );

        self::assertSame(405, $numeric->status);

        self::assertSame(
            'OPTIONS, PATCH, POST',
            $numeric->header('Allow'),
        );
    }

    public function testConflictingGroupRegistrationRestoresGroupScope(): void
    {
        $router = new Router();

        $router->group(
            '/api',
            static function (Router $group): void {
                $group->get(
                    '/status',
                    self::handler('first'),
                );
            },
        );

        try {
            $router->group(
                '/api',
                static function (Router $group): void {
                    $group->get(
                        '/status',
                        self::handler('duplicate'),
                    );
                },
            );

            self::fail(
                'Expected grouped duplicate route to be rejected.',
            );
        } catch (LogicException) {
            self::assertSame(1, $router->count());
        }

        $router->get(
            '/health',
            self::handler('root'),
        );

        self::assertSame(
            'root',
            $router->dispatch(
                new Request('GET', '/health'),
            )->body,
        );

        self::assertSame(
            'first',
            $router->dispatch(
                new Request('GET', '/api/status'),
            )->body,
        );

        self::assertSame(2, $router->count());
    }

    public function testFrozenRouterRejectsAdditionalMethodsWithoutMutation(): void
    {
        $router = new Router();

        $router->get(
            '/resource',
            self::handler('original'),
        );

        $router->freeze();

        try {
            $router->add(
                'OPTIONS',
                '/resource',
                self::handler('late'),
            );

            self::fail(
                'Expected frozen router to reject registration.',
            );
        } catch (LogicException) {
            self::assertSame(1, $router->count());
        }

        self::assertTrue($router->isFrozen());

        self::assertSame(
            'original',
            $router->dispatch(
                new Request('GET', '/resource'),
            )->body,
        );
    }

    /**
     * @return Closure(Request): Response
     */
    private static function handler(string $body): Closure
    {
        return static fn (Request $request): Response =>
            new Response(
                200,
                [],
                $body,
            );
    }
}
