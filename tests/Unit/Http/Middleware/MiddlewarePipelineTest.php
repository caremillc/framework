<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Middleware;

use Careminate\Http\Middleware\MiddlewarePipeline;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Closure;
use PHPUnit\Framework\TestCase;

final class MiddlewarePipelineTest extends TestCase
{
    public function testEmptyPipelineCallsTerminalHandler(): void
    {
        $pipeline = new MiddlewarePipeline();

        $response = $pipeline->handle(
            new Request('GET', '/health'),
            static fn (Request $request): Response =>
                new Response(200, [], 'healthy'),
        );

        self::assertSame(0, $pipeline->count());
        self::assertSame(200, $response->status);
        self::assertSame('healthy', $response->body);
    }

    public function testMiddlewareExecutesInOnionOrder(): void
    {
        $pipeline = new MiddlewarePipeline();
        $events = [];

        $pipeline->add(
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'first-before';

                $response = $next($request);

                self::assertInstanceOf(Response::class, $response);

                $events[] = 'first-after';

                return $response;
            },
        );

        $pipeline->add(
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'second-before';

                $response = $next($request);

                self::assertInstanceOf(Response::class, $response);

                $events[] = 'second-after';

                return $response;
            },
        );

        $response = $pipeline->handle(
            new Request('GET', '/'),
            static function (Request $request) use (&$events): Response {
                $events[] = 'handler';

                return new Response(200, [], 'complete');
            },
        );

        self::assertSame(
            [
                'first-before',
                'second-before',
                'handler',
                'second-after',
                'first-after',
            ],
            $events,
        );

        self::assertSame('complete', $response->body);
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $pipeline = new MiddlewarePipeline();
        $handlerCalled = false;

        $pipeline->add(
            static fn (
                Request $request,
                Closure $next,
            ): Response => new Response(403, [], 'Forbidden'),
        );

        $response = $pipeline->handle(
            new Request('GET', '/private'),
            static function (
                Request $request,
            ) use (&$handlerCalled): Response {
                $handlerCalled = true;

                return new Response(200, [], 'Allowed');
            },
        );

        self::assertSame(403, $response->status);
        self::assertSame('Forbidden', $response->body);
        self::assertFalse($handlerCalled);
    }

    public function testMiddlewareCanTransformResponse(): void
    {
        $pipeline = new MiddlewarePipeline();

        $pipeline->add(
            static function (
                Request $request,
                Closure $next,
            ): Response {
                $response = $next($request);

                self::assertInstanceOf(Response::class, $response);

                return new Response(
                    $response->status,
                    $response->headers,
                    '[' . $response->body . ']',
                );
            },
        );

        $response = $pipeline->handle(
            new Request('GET', '/'),
            static fn (Request $request): Response =>
                new Response(201, [], 'created'),
        );

        self::assertSame(201, $response->status);
        self::assertSame('[created]', $response->body);
    }

    public function testPipelineCanBeReusedAcrossRequests(): void
    {
        $pipeline = new MiddlewarePipeline();

        $pipeline->add(
            static function (
                Request $request,
                Closure $next,
            ): Response {
                $response = $next($request);

                self::assertInstanceOf(Response::class, $response);

                return $response;
            },
        );

        $handler = static fn (Request $request): Response =>
            new Response(200, [], $request->path());

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
        self::assertSame(1, $pipeline->count());
    }
}
