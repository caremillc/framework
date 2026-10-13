<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Routing;

use Flint\Http\Request;
use Flint\Http\Response;
use Flint\Routing\Router;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NamedRoutesTest extends TestCase
{
    public function testLiteralRouteCanGenerateItsPath(): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/health',
            static fn (Request $request): Response => new Response(),
            name: 'health',
        );

        self::assertSame('/health', $router->path('health'));
        self::assertSame(
            200,
            $router->handle(new Request(target: '/health'))->status(),
        );
    }

    public function testNamedRouteEncodesParameters(): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/hello/{name}',
            static fn (Request $request): Response => new Response(),
            name: 'greeting',
        );

        self::assertSame(
            '/hello/Ada%20Lovelace',
            $router->path('greeting', ['name' => 'Ada Lovelace']),
        );
    }

    public function testUnknownNameIsRejected(): void
    {
        $router = new Router();

        $this->expectException(InvalidArgumentException::class);

        $router->path('missing');
    }

    #[DataProvider('invalidNames')]
    public function testInvalidNamesAreRejected(string $name): void
    {
        $router = new Router();

        $this->expectException(InvalidArgumentException::class);

        $router->add(
            'GET',
            '/example',
            static fn (Request $request): Response => new Response(),
            name: $name,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => [' '];
        yield 'leading digit' => ['1route'];
        yield 'embedded space' => ['user profile'];
    }

    public function testDuplicateNameDoesNotRegisterTheSecondRoute(): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/first',
            static fn (Request $request): Response => new Response(body: 'First'),
            name: 'example',
        );

        $rejected = false;

        try {
            $router->add(
                'POST',
                '/second',
                static fn (Request $request): Response => new Response(body: 'Second'),
                name: 'example',
            );
        } catch (InvalidArgumentException) {
            $rejected = true;
        }

        self::assertTrue($rejected);
        self::assertSame('/first', $router->path('example'));
        self::assertSame(
            'First',
            $router->handle(new Request(target: '/first'))->body(),
        );
        self::assertSame(
            404,
            $router->handle(
                new Request(method: 'POST', target: '/second'),
            )->status(),
        );
    }

    public function testInvalidPatternDoesNotReserveItsName(): void
    {
        $router = new Router();
        $rejected = false;

        try {
            $router->add(
                'GET',
                '/users/{}',
                static fn (Request $request): Response => new Response(),
                name: 'users.show',
            );
        } catch (InvalidArgumentException) {
            $rejected = true;
        }

        self::assertTrue($rejected);

        $router->add(
            'GET',
            '/users/{id}',
            static fn (Request $request): Response => new Response(),
            name: 'users.show',
        );

        self::assertSame(
            '/users/42',
            $router->path('users.show', ['id' => '42']),
        );
    }

    public function testDuplicateRouteDoesNotReserveAnotherName(): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/first',
            static fn (Request $request): Response => new Response(),
            name: 'first',
        );

        $rejected = false;

        try {
            $router->add(
                'GET',
                '/first',
                static fn (Request $request): Response => new Response(),
                name: 'second',
            );
        } catch (InvalidArgumentException) {
            $rejected = true;
        }

        self::assertTrue($rejected);

        $router->add(
            'GET',
            '/second',
            static fn (Request $request): Response => new Response(),
            name: 'second',
        );

        self::assertSame('/first', $router->path('first'));
        self::assertSame('/second', $router->path('second'));
    }
}
