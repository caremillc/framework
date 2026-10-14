<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Routing;

use Closure;
use Flint\Container\Container;
use Flint\Http\ContainerRequestHandler;
use Flint\Http\Middleware;
use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;
use Flint\Routing\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

final class RouterMiddlewareTest extends TestCase
{
    public function testMiddlewareOrderParametersAndRequestIsolation(): void
    {
        $events = [];
        $router = new Router();

        $first = self::middleware(
            static function (
                Request $request,
                RequestHandler $next,
            ) use (&$events): Response {
                $events[] = 'first.in';

                self::assertSame(
                    ['name' => 'Ada'],
                    $request->attribute('route.parameters'),
                );

                $response = $next->handle(
                    $request->withAttribute('route.marker', 'present'),
                );

                $events[] = 'first.out';

                return $response;
            },
        );

        $second = self::middleware(
            static function (
                Request $request,
                RequestHandler $next,
            ) use (&$events): Response {
                $events[] = 'second.in';

                self::assertSame(
                    'present',
                    $request->attribute('route.marker'),
                );

                $response = $next->handle($request);

                $events[] = 'second.out';

                return $response;
            },
        );

        $destination = self::handler(
            static function (Request $request) use (&$events): Response {
                $events[] = 'handler';

                return ResponseFactory::text('Ready');
            },
        );

        $router->addHandler(
            'GET',
            '/hello/{name}',
            $destination,
            'greeting',
            $first,
            $second,
        );

        $request = new Request('GET', '/hello/Ada');

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $events = [];

            self::assertSame('Ready', $router->handle($request)->body());

            self::assertSame(
                [
                    'first.in',
                    'second.in',
                    'handler',
                    'second.out',
                    'first.out',
                ],
                $events,
            );

            self::assertFalse($request->hasAttribute('route.marker'));
            self::assertFalse($request->hasAttribute('route.parameters'));
        }
    }

    public function testShortCircuitPreventsServiceResolution(): void
    {
        $container = new Container();
        $created = 0;

        $container->bind(
            'handler',
            static function (ContainerInterface $services) use (
                &$created,
            ): RequestHandler {
                ++$created;

                return self::handler(
                    static fn (Request $request): Response =>
                        ResponseFactory::text('Unexpected'),
                );
            },
        );

        $router = new Router();

        $router->addHandler(
            'GET',
            '/protected',
            new ContainerRequestHandler($container, 'handler'),
            null,
            self::middleware(
                static fn (
                    Request $request,
                    RequestHandler $next,
                ): Response => ResponseFactory::text('Forbidden', 403),
            ),
        );

        self::assertSame(
            403,
            $router->handle(new Request('GET', '/protected'))->status(),
        );

        self::assertSame(0, $created);
    }

    public function testUnmatchedRequestsDoNotRunRouteMiddleware(): void
    {
        $entered = 0;
        $handled = 0;

        $router = new Router();

        $router->addHandler(
            'GET',
            '/known',
            self::handler(
                static function (Request $request) use (&$handled): Response {
                    ++$handled;

                    return ResponseFactory::text('Ready');
                },
            ),
            null,
            self::middleware(
                static function (
                    Request $request,
                    RequestHandler $next,
                ) use (&$entered): Response {
                    ++$entered;

                    return $next->handle($request);
                },
            ),
        );

        $missing = $router->handle(new Request('GET', '/missing'));
        $wrongMethod = $router->handle(new Request('POST', '/known'));

        self::assertSame(404, $missing->status());
        self::assertSame(405, $wrongMethod->status());

        self::assertSame(
            ['GET, HEAD'],
            $wrongMethod->headers()->get('Allow'),
        );

        self::assertSame(0, $entered);
        self::assertSame(0, $handled);
    }

    public function testHeadFallbackUsesTheSelectedRoutePipeline(): void
    {
        $router = new Router();

        $router->addHandler(
            'GET',
            '/hello/{name}',
            self::handler(
                static fn (Request $request): Response =>
                    ResponseFactory::text($request->method()),
            ),
            'greeting',
            self::middleware(
                static function (
                    Request $request,
                    RequestHandler $next,
                ): Response {
                    $response = $next->handle($request);

                    return $response->withHeaders(
                        $response->headers()->with('X-Route', 'greeting'),
                    );
                },
            ),
        );

        $response = $router->handle(new Request('HEAD', '/hello/Ada'));

        self::assertSame(200, $response->status());
        self::assertSame('HEAD', $response->body());

        self::assertSame(
            ['greeting'],
            $response->headers()->get('X-Route'),
        );

        self::assertSame(
            '/hello/Ada',
            $router->path('greeting', ['name' => 'Ada']),
        );
    }

    public function testExplicitHeadRouteBypassesGetMiddleware(): void
    {
        $getEntered = 0;
        $router = new Router();

        $router->addHandler(
            'GET',
            '/known',
            self::handler(
                static fn (Request $request): Response =>
                    ResponseFactory::text('GET'),
            ),
            null,
            self::middleware(
                static function (
                    Request $request,
                    RequestHandler $next,
                ) use (&$getEntered): Response {
                    ++$getEntered;

                    return $next->handle($request);
                },
            ),
        );

        $router->addHandler(
            'HEAD',
            '/known',
            self::handler(
                static fn (Request $request): Response =>
                    ResponseFactory::text('Explicit HEAD'),
            ),
        );

        self::assertSame(
            'Explicit HEAD',
            $router->handle(new Request('HEAD', '/known'))->body(),
        );

        self::assertSame(0, $getEntered);
    }

    public function testPipelineCanBeUsedAfterAHandlerThrows(): void
    {
        $attempts = 0;
        $events = [];
        $failure = new RuntimeException('First request failed.');
        $router = new Router();

        $router->addHandler(
            'GET',
            '/retry',
            self::handler(
                static function (Request $request) use (
                    &$attempts,
                    $failure,
                ): Response {
                    ++$attempts;

                    if ($attempts === 1) {
                        throw $failure;
                    }

                    return ResponseFactory::text('Recovered');
                },
            ),
            null,
            self::middleware(
                static function (
                    Request $request,
                    RequestHandler $next,
                ) use (&$events): Response {
                    $events[] = 'before';
                    $response = $next->handle($request);
                    $events[] = 'after';

                    return $response;
                },
            ),
        );

        try {
            $router->handle(new Request('GET', '/retry'));
            self::fail('Expected the first request to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame(
            'Recovered',
            $router->handle(new Request('GET', '/retry'))->body(),
        );

        self::assertSame(['before', 'before', 'after'], $events);
    }

    /**
     * @param Closure(Request): Response $callback
     */
    private static function handler(Closure $callback): RequestHandler
    {
        return new class ($callback) implements RequestHandler {
            /**
             * @param Closure(Request): Response $callback
             */
            public function __construct(
                private readonly Closure $callback,
            ) {
            }

            public function handle(Request $request): Response
            {
                return ($this->callback)($request);
            }
        };
    }

    /**
     * @param Closure(Request, RequestHandler): Response $callback
     */
    private static function middleware(Closure $callback): Middleware
    {
        return new class ($callback) implements Middleware {
            /**
             * @param Closure(Request, RequestHandler): Response $callback
             */
            public function __construct(
                private readonly Closure $callback,
            ) {
            }

            public function process(
                Request $request,
                RequestHandler $next,
            ): Response {
                return ($this->callback)($request, $next);
            }
        };
    }
}
