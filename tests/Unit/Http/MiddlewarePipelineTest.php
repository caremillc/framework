<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Closure;
use Flint\Http\Middleware;
use Flint\Http\MiddlewarePipeline;
use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MiddlewarePipelineTest extends TestCase
{
    public function testEmptyPipelinePreservesRequestAndResponseIdentity(): void
    {
        $request = new Request();
        $response = new Response(body: 'Ready');
        $received = null;

        $destination = self::handler(
            static function (Request $request) use (&$received, $response): Response {
                $received = $request;

                return $response;
            },
        );

        $pipeline = new MiddlewarePipeline($destination);

        self::assertSame($response, $pipeline->handle($request));
        self::assertSame($request, $received);
    }

    public function testMiddlewareRunsInRegistrationOrder(): void
    {
        $events = [];

        $first = self::middleware(
            static function (
                Request $request,
                RequestHandler $next,
            ) use (&$events): Response {
                $events[] = 'first before';
                $response = $next->handle($request);
                $events[] = 'first after';

                return $response;
            },
        );

        $second = self::middleware(
            static function (
                Request $request,
                RequestHandler $next,
            ) use (&$events): Response {
                $events[] = 'second before';
                $response = $next->handle($request);
                $events[] = 'second after';

                return $response;
            },
        );

        $destination = self::handler(
            static function (Request $request) use (&$events): Response {
                $events[] = 'handler';

                return new Response(body: 'Ready');
            },
        );

        $pipeline = new MiddlewarePipeline($destination, $first, $second);

        self::assertSame('Ready', $pipeline->handle(new Request())->body());
        self::assertSame(
            [
                'first before',
                'second before',
                'handler',
                'second after',
                'first after',
            ],
            $events,
        );
    }

    public function testMiddlewareCanReturnWithoutCallingNext(): void
    {
        $blocked = new Response(status: 403, body: 'Forbidden');

        $destination = self::handler(
            static function (Request $request): Response {
                throw new RuntimeException('Destination must not run.');
            },
        );

        $unreachable = self::middleware(
            static function (
                Request $request,
                RequestHandler $next,
            ): Response {
                throw new RuntimeException('Later middleware must not run.');
            },
        );

        $guard = self::middleware(
            static fn (
                Request $request,
                RequestHandler $next,
            ): Response => $blocked,
        );

        $pipeline = new MiddlewarePipeline(
            $destination,
            $guard,
            $unreachable,
        );

        self::assertSame($blocked, $pipeline->handle(new Request()));
    }

    public function testMiddlewareCanReplaceTheRequestPassedDownstream(): void
    {
        $original = new Request(target: '/original');
        $replacement = new Request(target: '/replacement');
        $received = null;

        $destination = self::handler(
            static function (Request $request) use (&$received): Response {
                $received = $request;

                return new Response(body: $request->path());
            },
        );

        $rewrite = self::middleware(
            static fn (
                Request $request,
                RequestHandler $next,
            ): Response => $next->handle($replacement),
        );

        $pipeline = new MiddlewarePipeline($destination, $rewrite);

        self::assertSame('/replacement', $pipeline->handle($original)->body());
        self::assertSame($replacement, $received);
        self::assertSame('/original', $original->path());
    }

    public function testMiddlewareCanModifyTheResponse(): void
    {
        $original = new Response(body: 'Ready');

        $destination = self::handler(
            static fn (Request $request): Response => $original,
        );

        $decorate = self::middleware(
            static function (
                Request $request,
                RequestHandler $next,
            ): Response {
                $response = $next->handle($request);

                return $response->withHeaders(
                    $response->headers()->with('X-Processed', 'yes'),
                );
            },
        );

        $pipeline = new MiddlewarePipeline($destination, $decorate);
        $response = $pipeline->handle(new Request());

        self::assertSame(['yes'], $response->headers()->get('X-Processed'));
        self::assertSame('Ready', $response->body());
        self::assertSame([], $original->headers()->get('X-Processed'));
    }

    public function testPipelineCanHandleMultipleRequests(): void
    {
        $calls = 0;

        $destination = self::handler(
            static fn (Request $request): Response => new Response(
                body: $request->path(),
            ),
        );

        $counter = self::middleware(
            static function (
                Request $request,
                RequestHandler $next,
            ) use (&$calls): Response {
                ++$calls;

                return $next->handle($request);
            },
        );

        $pipeline = new MiddlewarePipeline($destination, $counter);

        self::assertSame('/one', $pipeline->handle(new Request(target: '/one'))->body());
        self::assertSame('/two', $pipeline->handle(new Request(target: '/two'))->body());
        self::assertSame(2, $calls);
    }

    public function testDestinationExceptionPropagatesUnchanged(): void
    {
        $failure = new RuntimeException('Destination failed.');

        $destination = self::handler(
            static function (Request $request) use ($failure): Response {
                throw $failure;
            },
        );

        $passThrough = self::middleware(
            static fn (
                Request $request,
                RequestHandler $next,
            ): Response => $next->handle($request),
        );

        $pipeline = new MiddlewarePipeline($destination, $passThrough);

        try {
            $pipeline->handle(new Request());
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);

            return;
        }

        self::fail('The destination exception must propagate.');
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
