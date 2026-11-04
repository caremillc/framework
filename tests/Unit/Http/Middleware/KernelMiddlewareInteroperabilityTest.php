<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Middleware;

use Careminate\Foundation\Application;
use Careminate\Http\Kernel;
use Careminate\Http\Middleware\Middleware;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KernelMiddlewareInteroperabilityTest extends TestCase
{
    public function testCombinedMiddlewareExecutionOrder(): void
    {
        $events = [];
        $kernel = new Kernel(new Application());

        $recordEvent = static function (string $event) use (&$events): void {
            $events[] = $event;
        };

        $kernel->router()->get(
            '/order',
            static function (Request $request) use (&$events): Response {
                $events[] = 'route';

                return new Response(200, [], 'complete');
            },
        );

        $kernel->closureMiddleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'closure-a-before';

                $response = $next($request);
                self::assertInstanceOf(Response::class, $response);

                $events[] = 'closure-a-after';

                return $response;
            },
        );

        $kernel->closureMiddleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'closure-b-before';

                $response = $next($request);
                self::assertInstanceOf(Response::class, $response);

                $events[] = 'closure-b-after';

                return $response;
            },
        );

        $kernel->middleware(
            new class ($recordEvent) implements Middleware {
                /**
                 * @param Closure(string): void $recordEvent
                 */
                public function __construct(
                    private readonly Closure $recordEvent,
                ) {
                }

                /**
                 * @param Closure(Request): Response $next
                 */
                public function process(
                    Request $request,
                    Closure $next,
                ): Response {
                    ($this->recordEvent)('object-before');

                    $response = $next($request);
                    TestCase::assertInstanceOf(Response::class, $response);

                    ($this->recordEvent)('object-after');

                    return $response;
                }
            },
        );

        $response = $kernel->handle(
            new Request('GET', '/order'),
        );

        self::assertSame(
            [
                'closure-a-before',
                'closure-b-before',
                'object-before',
                'route',
                'object-after',
                'closure-b-after',
                'closure-a-after',
            ],
            $events,
        );

        self::assertSame('complete', $response->body);
    }

    public function testClosureShortCircuitSkipsObjectMiddlewareAndRouter(): void
    {
        $events = [];
        $kernel = new Kernel(new Application());

        $recordEvent = static function (string $event) use (&$events): void {
            $events[] = $event;
        };

        $kernel->router()->get(
            '/private',
            static function (Request $request) use (&$events): Response {
                $events[] = 'route';

                return new Response(200, [], 'allowed');
            },
        );

        $kernel->middleware(
            new class ($recordEvent) implements Middleware {
                /**
                 * @param Closure(string): void $recordEvent
                 */
                public function __construct(
                    private readonly Closure $recordEvent,
                ) {
                }

                /**
                 * @param Closure(Request): Response $next
                 */
                public function process(
                    Request $request,
                    Closure $next,
                ): Response {
                    ($this->recordEvent)('object');

                    return new Response(200, [], 'object');
                }
            },
        );

        $kernel->closureMiddleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'closure';

                return new Response(403, [], 'blocked');
            },
        );

        $response = $kernel->handle(
            new Request('GET', '/private'),
        );

        self::assertSame(403, $response->status);
        self::assertSame('blocked', $response->body);
        self::assertSame(['closure'], $events);
    }

    public function testObjectShortCircuitStillPassesThroughOuterClosure(): void
    {
        $events = [];
        $kernel = new Kernel(new Application());

        $recordEvent = static function (string $event) use (&$events): void {
            $events[] = $event;
        };

        $kernel->router()->get(
            '/private',
            static function (Request $request) use (&$events): Response {
                $events[] = 'route';

                return new Response(200, [], 'allowed');
            },
        );

        $kernel->middleware(
            new class ($recordEvent) implements Middleware {
                /**
                 * @param Closure(string): void $recordEvent
                 */
                public function __construct(
                    private readonly Closure $recordEvent,
                ) {
                }

                /**
                 * @param Closure(Request): Response $next
                 */
                public function process(
                    Request $request,
                    Closure $next,
                ): Response {
                    ($this->recordEvent)('object');

                    return new Response(401, [], 'denied');
                }
            },
        );

        $kernel->closureMiddleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'closure-before';

                $response = $next($request);
                self::assertInstanceOf(Response::class, $response);

                $events[] = 'closure-after';

                return new Response(
                    $response->status,
                    $response->headers,
                    'wrapped: ' . $response->body,
                );
            },
        );

        $response = $kernel->handle(
            new Request('GET', '/private'),
        );

        self::assertSame(401, $response->status);
        self::assertSame('wrapped: denied', $response->body);
        self::assertSame(
            ['closure-before', 'object', 'closure-after'],
            $events,
        );
    }

    public function testBothMiddlewareLayersTransformResponses(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/transform',
            static fn (Request $request): Response =>
                new Response(200, [], 'route'),
        );

        $kernel->middleware(
            new class () implements Middleware {
                /**
                 * @param Closure(Request): Response $next
                 */
                public function process(
                    Request $request,
                    Closure $next,
                ): Response {
                    $response = $next($request);
                    TestCase::assertInstanceOf(Response::class, $response);

                    return new Response(
                        $response->status,
                        $response->headers,
                        '[' . $response->body . ']',
                    );
                }
            },
        );

        $kernel->closureMiddleware(
            static function (
                Request $request,
                Closure $next,
            ): Response {
                $response = $next($request);
                self::assertInstanceOf(Response::class, $response);

                return new Response(
                    $response->status,
                    $response->headers,
                    '{' . $response->body . '}',
                );
            },
        );

        $response = $kernel->handle(
            new Request('GET', '/transform'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('{[route]}', $response->body);
    }

    public function testBootCallbacksCanRegisterBothMiddlewareTypes(): void
    {
        $application = new Application();
        $kernel = new Kernel($application);
        $events = [];

        $recordEvent = static function (string $event) use (&$events): void {
            $events[] = $event;
        };

        $kernel->router()->get(
            '/boot',
            static function (Request $request) use (&$events): Response {
                $events[] = 'route';

                return new Response(200, [], 'booted');
            },
        );

        $application->onBoot(
            static function (Application $application) use (
                $kernel,
                $recordEvent,
                &$events,
            ): void {
                $kernel->middleware(
                    new class ($recordEvent) implements Middleware {
                        /**
                         * @param Closure(string): void $recordEvent
                         */
                        public function __construct(
                            private readonly Closure $recordEvent,
                        ) {
                        }

                        /**
                         * @param Closure(Request): Response $next
                         */
                        public function process(
                            Request $request,
                            Closure $next,
                        ): Response {
                            ($this->recordEvent)('object');

                            $response = $next($request);
                            TestCase::assertInstanceOf(
                                Response::class,
                                $response,
                            );

                            return $response;
                        }
                    },
                );

                $kernel->closureMiddleware(
                    static function (
                        Request $request,
                        Closure $next,
                    ) use (&$events): Response {
                        $events[] = 'closure';

                        $response = $next($request);
                        self::assertInstanceOf(
                            Response::class,
                            $response,
                        );

                        return $response;
                    },
                );
            },
        );

        $response = $kernel->handle(
            new Request('GET', '/boot'),
        );

        self::assertSame(200, $response->status);
        self::assertSame(
            ['closure', 'object', 'route'],
            $events,
        );
        self::assertTrue($application->isBooted());
    }

    public function testBothMiddlewareRegistrationApisRejectLateChanges(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->handle(
            new Request('GET', '/missing'),
        );

        $objectRejected = false;
        $closureRejected = false;

        try {
            $kernel->middleware(
                new class () implements Middleware {
                    /**
                     * @param Closure(Request): Response $next
                     */
                    public function process(
                        Request $request,
                        Closure $next,
                    ): Response {
                        return new Response(200);
                    }
                },
            );
        } catch (LogicException) {
            $objectRejected = true;
        }

        try {
            $kernel->closureMiddleware(
                static fn (
                    Request $request,
                    Closure $next,
                ): Response => new Response(200),
            );
        } catch (LogicException) {
            $closureRejected = true;
        }

        self::assertTrue($objectRejected);
        self::assertTrue($closureRejected);
    }

    public function testDispatchStateRecoversAfterMiddlewareException(): void
    {
        $kernel = new Kernel(new Application());
        $calls = 0;

        $kernel->router()->get(
            '/recovery',
            static fn (Request $request): Response =>
                new Response(200, [], 'recovered'),
        );

        $kernel->closureMiddleware(
            static function (
                Request $request,
                Closure $next,
            ) use (&$calls): Response {
                $calls++;

                if ($calls === 1) {
                    throw new RuntimeException('Middleware failure.');
                }

                $response = $next($request);
                self::assertInstanceOf(Response::class, $response);

                return $response;
            },
        );

        try {
            $kernel->handle(
                new Request('GET', '/recovery'),
            );

            self::fail('Expected middleware exception.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Middleware failure.',
                $exception->getMessage(),
            );
        }

        $response = $kernel->handle(
            new Request('GET', '/recovery'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('recovered', $response->body);
        self::assertSame(2, $calls);
    }
}
