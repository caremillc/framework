<?php

declare (strict_types=1);

namespace Flint\Tests\Unit\Routing;

use Flint\Http\Request;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;
use Flint\Routing\Router;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

final class RouterTest extends TestCase
{
    public function testReturnsTheMatchedHandlerResponse(): void
    {
        $router   = new Router();
        $expected = ResponseFactory::text('Found');

        $router->add(
            'GET',
            '/items',
            static fn (Request $request): Response => $expected,
        );

        self::assertSame(
            $expected,
            $router->handle(new Request(target: '/items')),
        );
    }

    public function testQueryDoesNotAffectMatchingAndOriginalRequestIsUnchanged(): void
    {
        $router = new Router();

        $original = new Request(
            method: 'GET',
            target: '/health?probe=1',
            attributes: [
                'application.context' => 'preserved',
                'route.name'          => 'incoming.value',
            ],
        );

        $received = null;

        $router->add(
            'GET',
            '/health',
            static function (Request $request) use (&$received): \Flint\Http\Response {
                $received = $request;

                return \Flint\Http\ResponseFactory::text('ok');
            },
            'health',
        );

        $response = $router->handle($original);

        self::assertSame(200, $response->status());
        self::assertSame('ok', $response->body());

        self::assertInstanceOf(Request::class, $received);
        self::assertNotSame($original, $received);

        self::assertSame($original->method(), $received->method());
        self::assertSame($original->target(), $received->target());
        self::assertSame($original->path(), $received->path());
        self::assertSame('probe=1', $received->queryString());
        self::assertSame($original->headers(), $received->headers());
        self::assertSame($original->body(), $received->body());

        self::assertSame(
            'preserved',
            $received->attribute('application.context'),
        );

        self::assertSame('health', $received->attribute('route.name'));

        self::assertSame(
            'incoming.value',
            $original->attribute('route.name'),
        );

        self::assertSame('/health?probe=1', $original->target());
    }

    public function testUnknownPathReturnsNotFound(): void
    {
        $response = new Router()->handle(
            new Request(target: '/missing'),
        );

        self::assertSame(404, $response->status());
        self::assertFalse($response->headers()->has('Allow'));
    }

    public function testUnsupportedMethodReturnsAllowHeader(): void
    {
        $router = new Router();

        $router->add(
            'POST',
            '/items',
            static fn (Request $request): Response => new Response(),
        );
        $router->add(
            'GET',
            '/items',
            static fn (Request $request): Response => new Response(),
        );

        $response = $router->handle(
            new Request(method: 'DELETE', target: '/items'),
        );

        self::assertSame(405, $response->status());
        self::assertSame(
            ['GET, HEAD, POST'],
            $response->headers()->get('Allow'),
        );
    }

    public function testHeadFallsBackToGetWithoutRemovingBody(): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/items',
            static function (Request $request): Response {
                self::assertSame('HEAD', $request->method());

                return ResponseFactory::text('Representation');
            },
        );

        $response = $router->handle(
            new Request(method: 'HEAD', target: '/items'),
        );

        self::assertSame(200, $response->status());
        self::assertSame('Representation', $response->body());
    }

    public function testExplicitHeadRouteTakesPrecedence(): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/items',
            static fn (Request $request): Response =>
            ResponseFactory::text('GET handler'),
        );
        $router->add(
            'HEAD',
            '/items',
            static fn (Request $request): Response =>
            ResponseFactory::text('HEAD handler'),
        );

        $response = $router->handle(
            new Request(method: 'HEAD', target: '/items'),
        );

        self::assertSame('HEAD handler', $response->body());
    }

    public function testDuplicateRegistrationPreservesOriginalHandler(): void
    {
        $router   = new Router();
        $original = ResponseFactory::text('Original');

        $router->add(
            'GET',
            '/items',
            static fn (Request $request): Response => $original,
        );

        try {
            $router->add(
                'GET',
                '/items',
                static fn (Request $request): Response =>
                ResponseFactory::text('Replacement'),
            );

            self::fail('Duplicate registration must fail.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Route "GET /items" is already registered.',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            $original,
            $router->handle(new Request(target: '/items')),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRoutePaths(): iterable
    {
        yield 'relative path' => ['items'];
        yield 'query string'  => ['/items?page=2'];
        yield 'fragment'      => ['/items#details'];
    }

    #[DataProvider('invalidRoutePaths')]
    public function testRejectsInvalidRoutePath(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Router()->add(
            'GET',
            $path,
            static fn (Request $request): Response => new Response(),
        );
    }

    public function testRejectsInvalidMethod(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Router()->add(
            'GET POST',
            '/items',
            static fn (Request $request): Response => new Response(),
        );
    }

    public function testRejectsNonResponseHandlerResult(): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/items',
            static fn (Request $request): string => 'Invalid result',
        );

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'Route handlers must return a Response.',
        );

        $router->handle(new Request(target: '/items'));
    }

    public function testHandlerExceptionPropagatesUnchanged(): void
    {
        $router  = new Router();
        $failure = new RuntimeException('Handler failed.');

        $router->add(
            'GET',
            '/items',
            static function (Request $request) use ($failure): never {
                throw $failure;
            },
        );

        try {
            $router->handle(new Request(target: '/items'));

            self::fail('The handler exception must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function distinctPaths(): iterable
    {
        yield 'trailing slash' => ['/files/a/b/'];
        yield 'encoded slash'  => ['/files/a%2Fb'];
    }

    #[DataProvider('distinctPaths')]
    public function testDoesNotNormalizeDistinctPaths(string $path): void
    {
        $router = new Router();

        $router->add(
            'GET',
            '/files/a/b',
            static fn (Request $request): Response => new Response(),
        );

        self::assertSame(
            404,
            $router->handle(new Request(target: $path))->status(),
        );
    }
}
