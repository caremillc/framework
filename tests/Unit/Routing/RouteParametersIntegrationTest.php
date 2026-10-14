<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Routing;

use Flint\Http\Request;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;
use Flint\Routing\RouteConstraint;
use Flint\Routing\RouteParameters;
use Flint\Routing\Router;
use PHPUnit\Framework\TestCase;

final class RouteParametersIntegrationTest extends TestCase
{
    public function testRouterReplacesIncomingParameterContext(): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/items/{id}',
            static function (Request $request): Response {
                $parameters = RouteParameters::fromRequest($request);

                return ResponseFactory::text($parameters->get('id'));
            },
            'items.show',
            ['id' => RouteConstraint::Digits],
        );

        $router->freeze();

        $request = new Request(
            target: '/items/00042',
            attributes: [
                'route.parameters' => ['id' => 'untrusted'],
            ],
        );

        $response = $router->handle($request);

        self::assertSame(200, $response->status());
        self::assertSame('00042', $response->body());

        self::assertSame(
            ['id' => 'untrusted'],
            $request->attribute('route.parameters'),
        );
    }

    public function testRepeatedRequestsReceiveTheirOwnParameters(): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/hello/{name}',
            static function (Request $request): Response {
                return ResponseFactory::text(
                    RouteParameters::fromRequest($request)->get('name'),
                );
            },
        );

        $router->freeze();

        $first = $router->handle(
            new Request(target: '/hello/Ada'),
        );

        $second = $router->handle(
            new Request(target: '/hello/Grace'),
        );

        $third = $router->handle(
            new Request(target: '/hello/a%2Fb'),
        );

        self::assertSame('Ada', $first->body());
        self::assertSame('Grace', $second->body());
        self::assertSame('a%2Fb', $third->body());
    }
}
