<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Routing;

use Flint\Container\Container;
use Flint\Http\ContainerMiddleware;
use Flint\Http\ContainerRequestHandler;
use Flint\Http\Request;
use Flint\Http\Response;
use Flint\Routing\RouteConstraint;
use Flint\Routing\Router;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class RouterDescriptionTest extends TestCase
{
    public function testEmptyRouterHasNoDescriptions(): void
    {
        self::assertSame([], (new Router())->describe());
    }

    public function testDescriptionsPreserveRegistrationOrder(): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/health',
            self::unexpectedHandler(...),
            'health',
        );

        $router->add(
            'POST',
            '/items/{id}',
            self::unexpectedHandler(...),
            'items.store',
            ['id' => RouteConstraint::Digits],
        );

        $router->add(
            'GET',
            '/anonymous',
            self::unexpectedHandler(...),
        );

        self::assertSame(
            [
                [
                    'method' => 'GET',
                    'path' => '/health',
                    'name' => 'health',
                    'constraints' => [],
                ],
                [
                    'method' => 'POST',
                    'path' => '/items/{id}',
                    'name' => 'items.store',
                    'constraints' => ['id' => 'digits'],
                ],
                [
                    'method' => 'GET',
                    'path' => '/anonymous',
                    'name' => null,
                    'constraints' => [],
                ],
            ],
            $router->describe(),
        );
    }

    public function testDescriptionsIncludeExpandedGroupsAndConstraints(): void
    {
        $router = new Router();
        $container = new Container();

        $group = $router
            ->group('/teams/{team}', 'teams.')
            ->group('/items', 'items.');

        $group->addConstrainedHandler(
            'GET',
            '/{id}',
            new ContainerRequestHandler($container, 'unresolved.handler'),
            [
                'team' => RouteConstraint::Slug,
                'id' => RouteConstraint::Digits,
            ],
            'show',
        );

        self::assertSame(
            [
                [
                    'method' => 'GET',
                    'path' => '/teams/{team}/items/{id}',
                    'name' => 'teams.items.show',
                    'constraints' => [
                        'team' => 'slug',
                        'id' => 'digits',
                    ],
                ],
            ],
            $router->describe(),
        );
    }

    public function testReturnedDescriptionsAreIndependentSnapshots(): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/items/{id}',
            self::unexpectedHandler(...),
            'items.show',
            ['id' => RouteConstraint::Digits],
        );

        $snapshot = $router->describe();

        $snapshot[0]['path'] = '/changed';
        $snapshot[0]['constraints']['id'] = 'slug';

        self::assertSame(
            [
                [
                    'method' => 'GET',
                    'path' => '/items/{id}',
                    'name' => 'items.show',
                    'constraints' => ['id' => 'digits'],
                ],
            ],
            $router->describe(),
        );

        $earlier = $router->describe();

        $router->add(
            'GET',
            '/health',
            self::unexpectedHandler(...),
            'health',
        );

        self::assertCount(1, $earlier);
        self::assertCount(2, $router->describe());
    }

    public function testFailedRegistrationsDoNotAppear(): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/health',
            self::unexpectedHandler(...),
            'health',
        );

        $before = $router->describe();

        try {
            $router->add(
                'GET',
                '/health',
                self::unexpectedHandler(...),
                'duplicate',
            );

            self::fail('Expected duplicate registration to fail.');
        } catch (InvalidArgumentException) {
            self::assertSame($before, $router->describe());
        }
    }

    public function testListingDoesNotResolveServices(): void
    {
        $router = new Router();
        $container = new Container();

        $router->addHandler(
            'GET',
            '/protected',
            new ContainerRequestHandler($container, 'missing.handler'),
            'protected',
            new ContainerMiddleware($container, 'missing.middleware'),
        );

        self::assertSame(
            [
                [
                    'method' => 'GET',
                    'path' => '/protected',
                    'name' => 'protected',
                    'constraints' => [],
                ],
            ],
            $router->describe(),
        );
    }

    private static function unexpectedHandler(Request $request): Response
    {
        throw new LogicException(
            'Route inspection must not execute handlers.',
        );
    }
}
