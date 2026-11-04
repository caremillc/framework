<?php

declare (strict_types=1);

namespace Careminate\Tests\Unit\Http;

use Careminate\Foundation\Application;
use Careminate\Http\Kernel;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\Router;
use PHPUnit\Framework\TestCase;

final class OptionsMethodSemanticsTest extends TestCase
{
    private static function kernel(): Kernel
    {
        return new Kernel(new Application());
    }

    private static function handler(string $body): \Closure
    {
        return static fn (Request $request): Response =>
        new Response(200, [], $body);
    }

    public function testAutomaticOptionsForGetRoute(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/resource',
            self::handler('resource'),
        );

        $response = $kernel->handle(
            new Request('OPTIONS', '/resource'),
        );

        self::assertSame(204, $response->status);
        self::assertSame('', $response->body);
        self::assertSame(
            'GET, HEAD, OPTIONS',
            $response->header('Allow'),
        );
    }

    public function testAutomaticOptionsForPostRoute(): void
    {
        $kernel = self::kernel();

        $kernel->router()->post(
            '/resource',
            self::handler('created'),
        );

        $response = $kernel->handle(
            new Request('OPTIONS', '/resource'),
        );

        self::assertSame(204, $response->status);
        self::assertSame(
            'OPTIONS, POST',
            $response->header('Allow'),
        );
    }

    public function testAutomaticOptionsCombinesMethods(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/resource',
            self::handler('read'),
        );

        $kernel->router()->post(
            '/resource',
            self::handler('write'),
        );

        $response = $kernel->handle(
            new Request('OPTIONS', '/resource'),
        );

        self::assertSame(204, $response->status);
        self::assertSame(
            'GET, HEAD, OPTIONS, POST',
            $response->header('Allow'),
        );
        self::assertSame('', $response->body);
    }

    public function testExplicitOptionsRouteOverridesAutomaticResponse(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/resource',
            self::handler('read'),
        );

        $kernel->router()->add(
            'OPTIONS',
            '/resource',
            static fn (Request $request): Response =>
            new Response(
                200,
                ['X-Explicit-Options' => 'yes'],
                'custom options',
            ),
        );

        $response = $kernel->handle(
            new Request('OPTIONS', '/resource'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('custom options', $response->body);
        self::assertSame(
            'yes',
            $response->header('X-Explicit-Options'),
        );
        self::assertSame(2, $kernel->router()->count());
    }

    public function testExplicitDynamicOptionsRouteOverridesFallback(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/users/{id}',
            self::handler('read'),
        );

        $kernel->router()->add(
            'OPTIONS',
            '/users/{id}',
            static function (Request $request): Response {
                return new Response(
                    200,
                    [
                        'X-User' =>
                        $request->routeParameter('id') ?? '',
                    ],
                    'explicit',
                );
            },
        );

        $response = $kernel->handle(
            new Request('OPTIONS', '/users/42'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('42', $response->header('X-User'));
        self::assertSame('explicit', $response->body);
    }

    public function testAutomaticOptionsSupportsDynamicRoutes(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/users/{id}',
            self::handler('user'),
            ['id' => '[0-9]+'],
        );

        $response = $kernel->handle(
            new Request('OPTIONS', '/users/42'),
        );

        self::assertSame(204, $response->status);
        self::assertSame(
            'GET, HEAD, OPTIONS',
            $response->header('Allow'),
        );
    }

    public function testAutomaticOptionsRespectsConstraints(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/users/{id}',
            self::handler('user'),
            ['id' => '[0-9]+'],
        );

        $response = $kernel->handle(
            new Request('OPTIONS', '/users/abc'),
        );

        self::assertSame(404, $response->status);
        self::assertNull($response->header('Allow'));
    }

    public function testUnknownPathDoesNotGenerateOptionsResponse(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/known',
            self::handler('known'),
        );

        $response = $kernel->handle(
            new Request('OPTIONS', '/missing'),
        );

        self::assertSame(404, $response->status);
        self::assertSame('Not Found', $response->body);
    }

    public function testMethodNotAllowedAdvertisesAutomaticOptions(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/resource',
            self::handler('read'),
        );

        $response = $kernel->handle(
            new Request('DELETE', '/resource'),
        );

        self::assertSame(405, $response->status);
        self::assertSame(
            'GET, HEAD, OPTIONS',
            $response->header('Allow'),
        );
    }

    public function testAutomaticOptionsDoesNotExecuteGetHandler(): void
    {
        $kernel = self::kernel();
        $calls  = 0;

        $kernel->router()->get(
            '/resource',
            static function (Request $request) use (&$calls): Response {
                ++$calls;

                return new Response(200, [], 'executed');
            },
        );

        for ($i = 0; $i < 10; ++$i) {
            $response = $kernel->handle(
                new Request('OPTIONS', '/resource'),
            );

            self::assertSame(204, $response->status);
        }

        self::assertSame(0, $calls);
    }

    public function testAutomaticOptionsDoesNotExecuteRouteGroupMiddleware(): void
    {
        $kernel = self::kernel();
        $calls  = 0;

        $middleware = new class (
            static function () use (&$calls): void {
                ++$calls;
            },
        ) implements \Careminate\Http\Middleware\Middleware {
            public function __construct(
                private readonly \Closure $onCall,
            ) {
            }

            /** @param \Closure(Request): Response $next */
            public function process(
                Request $request,
                \Closure $next,
            ): Response {
                ($this->onCall)();

                return $next($request);
            }
        };

        $kernel->router()->group(
            '/api',
            static function (Router $router): void {
                $router->get(
                    '/resource',
                    static fn (Request $request): Response =>
                    new Response(200),
                );
            },
            [$middleware],
        );

        $response = $kernel->handle(
            new Request('OPTIONS', '/api/resource'),
        );

        self::assertSame(204, $response->status);
        self::assertSame(0, $calls);
    }

    public function testRepeatedOptionsAndGetRequestsRemainIndependent(): void
    {
        $kernel = self::kernel();

        $kernel->router()->get(
            '/resource',
            self::handler('visible body'),
        );

        for ($i = 0; $i < 20; ++$i) {
            $options = $kernel->handle(
                new Request('OPTIONS', '/resource'),
            );

            $get = $kernel->handle(
                new Request('GET', '/resource'),
            );

            self::assertSame(204, $options->status);
            self::assertSame('', $options->body);
            self::assertSame(200, $get->status);
            self::assertSame('visible body', $get->body);
        }
    }


}
