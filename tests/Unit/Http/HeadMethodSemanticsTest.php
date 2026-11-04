<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http;

use Careminate\Foundation\Application;
use Careminate\Http\Kernel;
use Careminate\Http\Request;
use Careminate\Http\Response;
use PHPUnit\Framework\TestCase;

final class HeadMethodSemanticsTest extends TestCase
{
    private static function kernel(): Kernel
    {
        return new Kernel(new Application());
    }

    public function testHeadFallsBackToGet(): void
    {
        $kernel = self::kernel();
        $calls = 0;

        $kernel->router()->get(
            '/resource',
            static function (Request $request) use (&$calls): Response {
                ++$calls;

                return new Response(
                    200,
                    ['X-Resource' => 'available'],
                    'response body',
                );
            },
        );

        $response = $kernel->handle(
            new Request('HEAD', '/resource'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
        self::assertSame(
            'available',
            $response->header('X-Resource'),
        );
        self::assertSame(1, $calls);
    }

    public function testExplicitHeadRouteOverridesGet(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/resource',
            static fn (Request $request): Response =>
                new Response(200, [], 'GET handler'),
        );

        $kernel->router()->add(
            'HEAD',
            '/resource',
            static fn (Request $request): Response =>
                new Response(
                    202,
                    ['X-Handler' => 'HEAD'],
                    'HEAD handler',
                ),
        );

        $response = $kernel->handle(
            new Request('HEAD', '/resource'),
        );

        self::assertSame(202, $response->status);
        self::assertSame('HEAD', $response->header('X-Handler'));
        self::assertSame('', $response->body);
        self::assertSame(2, $kernel->router()->count());
    }

    public function testGetFallbackPreservesHeadMethod(): void
    {
        $kernel = self::kernel();
        $seenMethod = null;

        $kernel->router()->get(
            '/resource',
            static function (
                Request $request,
            ) use (&$seenMethod): Response {
                $seenMethod = $request->method;

                return new Response(200, [], 'body');
            },
        );

        $kernel->handle(new Request('HEAD', '/resource'));

        self::assertSame('HEAD', $seenMethod);
    }

    public function testHeadFallbackSupportsDynamicParameters(): void
    {
        $kernel = self::kernel();
        $seenId = null;

        $kernel->router()->get(
            '/users/{id}',
            static function (
                Request $request,
            ) use (&$seenId): Response {
                $seenId = $request->routeParameter('id');

                return new Response(
                    200,
                    ['X-User' => $seenId ?? ''],
                    'user',
                );
            },
            ['id' => '[0-9]+'],
        );

        $response = $kernel->handle(
            new Request('HEAD', '/users/42'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('42', $seenId);
        self::assertSame('42', $response->header('X-User'));
        self::assertSame('', $response->body);
    }

    public function testExplicitDynamicHeadOverridesStaticGet(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/users/me',
            static fn (Request $request): Response =>
                new Response(200, ['X-Handler' => 'GET']),
        );

        $kernel->router()->add(
            'HEAD',
            '/users/{id}',
            static fn (Request $request): Response =>
                new Response(200, ['X-Handler' => 'HEAD']),
        );

        $response = $kernel->handle(
            new Request('HEAD', '/users/me'),
        );

        self::assertSame('HEAD', $response->header('X-Handler'));
        self::assertSame('', $response->body);
    }

    public function testHeadDoesNotFallBackToPost(): void
    {
        $kernel = self::kernel();

        $kernel->router()->post(
            '/resource',
            static fn (Request $request): Response =>
                new Response(201, [], 'created'),
        );

        $response = $kernel->handle(
            new Request('HEAD', '/resource'),
        );

        self::assertSame(405, $response->status);
        // self::assertSame('POST', $response->header('Allow'));
        // self::assertSame('POST, HEAD', $response->header('Allow'));
        self::assertSame('OPTIONS, POST', $response->header('Allow'), );
        self::assertSame('', $response->body);
    }

    public function testMissingHeadRouteReturnsBodyless404(): void
    {
        $kernel = self::kernel();

        $response = $kernel->handle(
            new Request('HEAD', '/missing'),
        );

        self::assertSame(404, $response->status);
        self::assertSame('', $response->body);
    }

    public function testAllowHeaderIncludesImplicitHead(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/resource',
            static fn (Request $request): Response =>
                new Response(200),
        );

        $kernel->router()->post(
            '/resource',
            static fn (Request $request): Response =>
                new Response(201),
        );

        $response = $kernel->handle(
            new Request('DELETE', '/resource'),
        );

        self::assertSame(405, $response->status);
        // self::assertSame('GET, HEAD', $response->header('Allow'));
        self::assertSame('GET, HEAD, OPTIONS, POST', $response->header('Allow'), );
    }

    public function testHeadSuppressesGlobalMiddlewareResponseBody(): void
    {
        $kernel = self::kernel();

        $kernel->closureMiddleware(
            static fn (
                Request $request,
                \Closure $next,
            ): Response => new Response(
                403,
                ['X-Blocked' => 'yes'],
                'blocked',
            ),
        );

        $response = $kernel->handle(
            new Request('HEAD', '/anything'),
        );

        self::assertSame(403, $response->status);
        self::assertSame('yes', $response->header('X-Blocked'));
        self::assertSame('', $response->body);
    }

    public function testRepeatedHeadAndGetRequestsRemainIndependent(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/resource',
            static fn (Request $request): Response =>
                new Response(200, [], 'visible body'),
        );

        for ($i = 0; $i < 20; ++$i) {
            $head = $kernel->handle(
                new Request('HEAD', '/resource'),
            );

            $get = $kernel->handle(
                new Request('GET', '/resource'),
            );

            self::assertSame(200, $head->status);
            self::assertSame('', $head->body);
            self::assertSame(200, $get->status);
            self::assertSame('visible body', $get->body);
        }
    }
}
