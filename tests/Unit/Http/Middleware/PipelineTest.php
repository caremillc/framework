<?php

declare (strict_types=1);

namespace Careminate\Tests\Unit\Http\Middleware;

use Careminate\Http\Middleware\Middleware;
use Careminate\Http\Middleware\Pipeline;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\Router;
use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PipelineTest extends TestCase
{
    public function testEmptyPipelineCallsHandler(): void
    {
        $pipeline = new Pipeline();

        $response = $pipeline->handle(
            new Request(),
            static fn (Request $request): Response =>
            new Response(200, body: 'OK'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('OK', $response->body);
        self::assertSame(0, $pipeline->count());
    }

    public function testMiddlewareExecutesInOnionOrder(): void
    {
        $events   = [];
        $pipeline = new Pipeline();

        $pipeline->add(
            $this->trackingMiddleware('first', $events)
        );

        $pipeline->add(
            $this->trackingMiddleware('second', $events)
        );

        $response = $pipeline->handle(
            new Request(),
            static function (Request $request) use (&$events): Response {
                $events[] = 'handler';

                return new Response(200);
            },
        );

        self::assertSame(200, $response->status);
        self::assertSame(
            [
                'first:before',
                'second:before',
                'handler',
                'second:after',
                'first:after',
            ],
            $events,
        );
    }

    public function testMiddlewareCanModifyRequest(): void
    {
        $pipeline = new Pipeline();

        $pipeline->add(
            new class () implements Middleware {
                public function process(
                    Request $request,
                    Closure $next,
                ): Response {
                    return $next(
                        $request->withHeader(
                            'X-Processed',
                            'yes',
                        )
                    );
                }
            }
        );

        $response = $pipeline->handle(
            new Request(),
            static fn (Request $request): Response =>
            new Response(
                200,
                body: $request->header('X-Processed') ?? '',
            ),
        );

        self::assertSame('yes', $response->body);
    }

    public function testMiddlewareCanModifyResponse(): void
    {
        $pipeline = new Pipeline();

        $pipeline->add(
            new class () implements Middleware {
                public function process(
                    Request $request,
                    Closure $next,
                ): Response {
                    return $next($request)->withHeader(
                        'X-Middleware',
                        'active',
                    );
                }
            }
        );

        $response = $pipeline->handle(
            new Request(),
            static fn (Request $request): Response =>
            new Response(),
        );

        self::assertSame(
            'active',
            $response->header('X-Middleware'),
        );
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $pipeline      = new Pipeline();
        $handlerCalled = false;

        $pipeline->add(
            new class () implements Middleware {
                public function process(
                    Request $request,
                    Closure $next,
                ): Response {
                    return new Response(
                        403,
                        body: 'Forbidden',
                    );
                }
            }
        );

        $response = $pipeline->handle(
            new Request(),
            static function (Request $request) use (
                &$handlerCalled,
            ): Response {
                $handlerCalled = true;

                return new Response(200);
            },
        );

        self::assertSame(403, $response->status);
        self::assertSame('Forbidden', $response->body);
        self::assertFalse($handlerCalled);
    }

    public function testPipelineDispatchesThroughRouter(): void
    {
        $router = new Router();

        $router->get(
            '/health',
            static fn (Request $request): Response =>
            new Response(200, body: 'healthy'),
        );

        $pipeline = new Pipeline();

        $pipeline->add(
            new class () implements Middleware {
                public function process(
                    Request $request,
                    Closure $next,
                ): Response {
                    return $next($request)->withHeader(
                        'X-Pipeline',
                        'yes',
                    );
                }
            }
        );

        $response = $pipeline->dispatch(
            new Request('GET', '/health'),
            $router,
        );

        self::assertSame(200, $response->status);
        self::assertSame('healthy', $response->body);
        self::assertSame(
            'yes',
            $response->header('X-Pipeline'),
        );
    }

    public function testMiddlewareCanInterceptRouter404(): void
    {
        $router   = new Router();
        $pipeline = new Pipeline();

        $pipeline->add(
            new class () implements Middleware {
                public function process(
                    Request $request,
                    Closure $next,
                ): Response {
                    $response = $next($request);

                    if ($response->status === 404) {
                        return $response->withHeader(
                            'X-Not-Found',
                            'yes',
                        );
                    }

                    return $response;
                }
            }
        );

        $response = $pipeline->dispatch(
            new Request('GET', '/missing'),
            $router,
        );

        self::assertSame(404, $response->status);
        self::assertSame(
            'yes',
            $response->header('X-Not-Found'),
        );
    }

    public function testHandlerExceptionPropagates(): void
    {
        $pipeline = new Pipeline();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Handler failed');

        $pipeline->handle(
            new Request(),
            static function (Request $request): Response {
                throw new RuntimeException('Handler failed');
            },
        );
    }

    public function testPipelineCanBeReused(): void
    {
        $pipeline = new Pipeline();

        $pipeline->add(
            new class () implements Middleware {
                public function process(
                    Request $request,
                    Closure $next,
                ): Response {
                    return $next($request)->withHeader(
                        'X-Processed',
                        'yes',
                    );
                }
            }
        );

        $handler = static fn (Request $request): Response =>
        new Response(200, body: $request->path());

        $first = $pipeline->handle(
            new Request('GET', '/first'),
            $handler,
        );

        $second = $pipeline->handle(
            new Request('GET', '/second'),
            $handler,
        );

        self::assertSame('/first', $first->body);
        self::assertSame('/second', $second->body);
        self::assertSame('yes', $first->header('X-Processed'));
        self::assertSame('yes', $second->header('X-Processed'));
    }

    public function testPipelineCountsMiddleware(): void
    {
        $pipeline = new Pipeline();

        $pipeline->add(
            new class () implements Middleware {
                public function process(
                    Request $request,
                    Closure $next,
                ): Response {
                    return $next($request);
                }
            }
        );

        self::assertSame(1, $pipeline->count());
    }


    /**
     * @param list<string> $events
     */
    private function trackingMiddleware(
        string $name,
        array &$events,
    ): Middleware {
        $record = static function (string $event) use (&$events): void {
            $events[] = $event;
        };

        return new class ($name, $record) implements Middleware {
            /**
             * @param Closure(string): void $record
             */
            public function __construct(
                private readonly string $name,
                private readonly Closure $record,
            ) {
            }

            public function process(
                Request $request,
                Closure $next,
            ): Response {
                ($this->record)($this->name . ':before');

                $response = $next($request);

                ($this->record)($this->name . ':after');

                return $response;
            }
        };
    }

}
