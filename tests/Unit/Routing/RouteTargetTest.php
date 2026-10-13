<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Routing;

use Flint\Http\Request;
use Flint\Http\Response;
use Flint\Routing\Router;
use PHPUnit\Framework\TestCase;

final class RouteTargetTest extends TestCase
{
    public function testNamedTargetCombinesEncodedPathAndQuery(): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/hello/{name}',
            static fn (Request $request): Response => new Response(),
            name: 'greeting',
        );

        self::assertSame(
            '/hello/Ada%20Lovelace?source=email&preview=1',
            $router->target(
                'greeting',
                ['name' => 'Ada Lovelace'],
                ['source' => 'email', 'preview' => true],
            ),
        );
    }

    public function testEmptyQueryDoesNotAppendAQuestionMark(): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/health',
            static fn (Request $request): Response => new Response(),
            name: 'health',
        );

        self::assertSame('/health', $router->target('health'));
        self::assertSame(
            '/health',
            $router->target('health', query: ['unused' => null]),
        );
    }

    public function testGeneratedTargetCanBeDispatched(): void
    {
        $router = new Router();
        $received = null;

        $router->add(
            'GET',
            '/files/{name}',
            static function (Request $request) use (&$received): Response {
                $received = $request;

                return new Response(body: 'Matched');
            },
            name: 'files.show',
        );

        $target = $router->target(
            'files.show',
            ['name' => 'a/b'],
            ['search' => 'one&two'],
        );

        $response = $router->handle(new Request(target: $target));

        self::assertSame('Matched', $response->body());
        self::assertInstanceOf(Request::class, $received);
        self::assertSame(
            ['name' => 'a%2Fb'],
            $received->attribute('route.parameters'),
        );
        self::assertSame('search=one%26two', $received->queryString());
    }
}
