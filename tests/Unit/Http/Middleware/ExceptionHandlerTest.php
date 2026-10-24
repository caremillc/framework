<?php

declare (strict_types=1);

namespace Careminate\Tests\Unit\Http\Middleware;

use Careminate\Http\Exception\HttpException;
use Careminate\Http\Middleware\ExceptionHandler;
use Careminate\Http\Middleware\Middleware;
use Careminate\Http\Middleware\Pipeline;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\Router;
use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExceptionHandlerTest extends TestCase
{
    public function testNormalResponsePassesThrough(): void
    {
        $pipeline = new Pipeline();
        $pipeline->add(new ExceptionHandler());

        $response = $pipeline->handle(
            new Request(),
            static fn (Request $request): Response =>
            new Response(200, body: 'OK'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('OK', $response->body);
    }

    public function testHttpExceptionBecomesSafeResponse(): void
    {
        $pipeline = new Pipeline();
        $pipeline->add(new ExceptionHandler());

        $response = $pipeline->handle(
            new Request(),
            static function (Request $request): Response {
                throw new HttpException(
                    403,
                    'Sensitive authorization details',
                );
            },
        );

        self::assertSame(403, $response->status);
        self::assertSame('Forbidden', $response->body);
        self::assertSame(
            'no-store',
            $response->header('Cache-Control'),
        );
        self::assertSame(
            'nosniff',
            $response->header('X-Content-Type-Options'),
        );
        self::assertStringNotContainsString(
            'Sensitive',
            $response->body,
        );
    }

    public function testUnexpectedExceptionBecomes500(): void
    {
        $pipeline = new Pipeline();
        $pipeline->add(new ExceptionHandler());

        $response = $pipeline->handle(
            new Request(),
            static function (Request $request): Response {
                throw new RuntimeException(
                    'Database password is secret',
                );
            },
        );

        self::assertSame(500, $response->status);
        self::assertSame(
            'Internal Server Error',
            $response->body,
        );
        self::assertStringNotContainsString(
            'password',
            $response->body,
        );
    }

    public function testHandlerCatchesDownstreamMiddlewareFailure(): void
    {
        $pipeline = new Pipeline();
        $pipeline->add(new ExceptionHandler());

        $pipeline->add(
            new class () implements Middleware {
                public function process(
                    Request $request,
                    Closure $next,
                ): Response {
                    throw new RuntimeException(
                        'Middleware failed'
                    );
                }
            }
        );

        $response = $pipeline->handle(
            new Request(),
            static fn (Request $request): Response =>
            new Response(200),
        );

        self::assertSame(500, $response->status);
    }

    public function testHandlerCatchesRouterFailure(): void
    {
        $router = new Router();

        $router->get(
            '/failure',
            static function (Request $request): Response {
                throw new RuntimeException('Private failure');
            },
        );

        $pipeline = new Pipeline();
        $pipeline->add(new ExceptionHandler());

        $response = $pipeline->dispatch(
            new Request('GET', '/failure'),
            $router,
        );

        self::assertSame(500, $response->status);
        self::assertSame(
            'Internal Server Error',
            $response->body,
        );
    }

    public function testUnknownHttpErrorUsesGenericReason(): void
    {
        $pipeline = new Pipeline();
        $pipeline->add(new ExceptionHandler());

        $response = $pipeline->handle(
            new Request(),
            static function (Request $request): Response {
                throw new HttpException(
                    499,
                    'Internal custom error',
                );
            },
        );

        self::assertSame(499, $response->status);
        self::assertSame('Client Error', $response->body);
    }
}
