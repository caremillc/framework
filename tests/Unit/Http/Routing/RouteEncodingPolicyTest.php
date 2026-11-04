<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Routing;

use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\RoutePattern;
use Careminate\Http\Routing\Router;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RouteEncodingPolicyTest extends TestCase
{
    public function testDecodesSpaceOnce(): void
    {
        $pattern = new RoutePattern('/search/{term}');

        self::assertSame(
            ['term' => 'hello world'],
            $pattern->match('/search/hello%20world'),
        );
    }

    public function testDecodesUnicode(): void
    {
        $pattern = new RoutePattern('/search/{term}');

        self::assertSame(
            ['term' => 'café'],
            $pattern->match('/search/caf%C3%A9'),
        );
    }

    public function testRejectsEncodedForwardSlash(): void
    {
        $pattern = new RoutePattern('/files/{name}');

        self::assertNull($pattern->match('/files/a%2Fb'));
    }

    public function testRejectsEncodedBackslash(): void
    {
        $pattern = new RoutePattern('/files/{name}');

        self::assertNull($pattern->match('/files/a%5Cb'));
    }

    public function testDoesNotDecodeRecursively(): void
    {
        $pattern = new RoutePattern('/files/{name}');

        self::assertSame(
            ['name' => '%2F'],
            $pattern->match('/files/%252F'),
        );
    }

    public function testRejectsMalformedPercentEscape(): void
    {
        $pattern = new RoutePattern('/files/{name}');

        self::assertNull($pattern->match('/files/%ZZ'));
    }

    public function testRejectsEncodedControlCharacter(): void
    {
        $pattern = new RoutePattern('/files/{name}');

        self::assertNull($pattern->match('/files/a%00b'));
    }

    public function testRejectsInvalidUtf8(): void
    {
        $pattern = new RoutePattern('/files/{name}');

        self::assertNull($pattern->match('/files/%FF'));
    }

    public function testConstraintsApplyAfterDecoding(): void
    {
        $pattern = new RoutePattern(
            '/users/{id}',
            ['id' => '[0-9]+'],
        );

        self::assertSame(
            ['id' => '42'],
            $pattern->match('/users/%34%32'),
        );

        self::assertNull(
            $pattern->match('/users/%61%62'),
        );
    }

    public function testGeneratedUrlDispatchesWithDecodedParameter(): void
    {
        $router = new Router();

        $router->get(
            '/search/{term}',
            static fn (Request $request): Response => new Response(
                200,
                [],
                $request->routeParameter('term') ?? '',
            ),
            [],
            'search',
        );

        $url = $router->url('search', [
            'term' => 'hello world',
        ]);

        self::assertSame(
            '/search/hello%20world',
            $url,
        );

        $response = $router->dispatch(
            new Request('GET', $url),
        );

        self::assertSame(200, $response->status);
        self::assertSame('hello world', $response->body);
    }

    public function testGeneratedConstrainedUrlDispatches(): void
    {
        $router = new Router();

        $router->get(
            '/users/{id}',
            static fn (Request $request): Response => new Response(
                200,
                [],
                $request->routeParameter('id') ?? '',
            ),
            ['id' => '[0-9]+'],
            'users.show',
        );

        $url = $router->url('users.show', [
            'id' => 42,
        ]);

        $response = $router->dispatch(
            new Request('GET', $url),
        );

        self::assertSame(200, $response->status);
        self::assertSame('42', $response->body);
    }

    public function testTrailingSlashesAreDistinct(): void
    {
        $router = new Router();

        $router->get(
            '/users',
            static fn (Request $request): Response =>
                new Response(200, [], 'without-slash'),
        );

        $router->get(
            '/users/',
            static fn (Request $request): Response =>
                new Response(200, [], 'with-slash'),
        );

        self::assertSame(
            'without-slash',
            $router->dispatch(
                new Request('GET', '/users'),
            )->body,
        );

        self::assertSame(
            'with-slash',
            $router->dispatch(
                new Request('GET', '/users/'),
            )->body,
        );
    }

    public function testGeneratedParameterRejectsInvalidUtf8(): void
    {
        $pattern = new RoutePattern('/files/{name}');

        $this->expectException(
            InvalidArgumentException::class,
        );

        $pattern->generate([
            'name' => "\xFF",
        ]);
    }
    public function testGeneratedLiteralPercentIsDecodedOnlyOnce(): void
    {
        $router = new Router();

        $router->get(
            '/files/{name}',
            static fn (Request $request): Response => new Response(
                200,
                [],
                $request->routeParameter('name') ?? '',
            ),
            [],
            'files.show',
        );

        $url = $router->url('files.show', [
            'name' => '%2F',
        ]);

        self::assertSame('/files/%252F', $url);

        $response = $router->dispatch(
            new Request('GET', $url),
        );

        self::assertSame(200, $response->status);
        self::assertSame('%2F', $response->body);
    }

}
