<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Middleware;

use Careminate\Http\Middleware\Middleware;
use Careminate\Http\Middleware\Pipeline;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;

final class PipelineFreezeTest extends TestCase
{
    public function testPipelineStartsMutable(): void
    {
        $pipeline = new Pipeline();

        self::assertFalse($pipeline->isFrozen());
    }

    public function testFreezeMarksPipelineAsFrozen(): void
    {
        $pipeline = new Pipeline();

        $pipeline->freeze();

        self::assertTrue($pipeline->isFrozen());
    }

    public function testFreezeIsIdempotent(): void
    {
        $pipeline = new Pipeline();

        $pipeline->freeze();
        $pipeline->freeze();

        self::assertTrue($pipeline->isFrozen());
    }

    public function testFrozenPipelineRejectsRegistration(): void
    {
        $pipeline = new Pipeline();
        $pipeline->freeze();

        $this->expectException(LogicException::class);

        $pipeline->add($this->passthroughMiddleware());
    }

    public function testExistingMiddlewareRunsAfterFreeze(): void
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

        $pipeline->freeze();

        $response = $pipeline->handle(
            new Request(),
            static fn (Request $request): Response =>
                new Response(200),
        );

        self::assertSame(
            'active',
            $response->header('X-Middleware'),
        );
        self::assertSame(1, $pipeline->count());
    }

    public function testFrozenPipelineCanBeReused(): void
    {
        $pipeline = new Pipeline();
        $pipeline->add($this->passthroughMiddleware());
        $pipeline->freeze();

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
        self::assertTrue($pipeline->isFrozen());
    }

    public function testFrozenEmptyPipelineStillDispatches(): void
    {
        $pipeline = new Pipeline();
        $pipeline->freeze();

        $response = $pipeline->handle(
            new Request(),
            static fn (Request $request): Response =>
                new Response(200, body: 'OK'),
        );

        self::assertSame('OK', $response->body);
    }

    private function passthroughMiddleware(): Middleware
    {
        return new class () implements Middleware {
            public function process(
                Request $request,
                Closure $next,
            ): Response {
                return $next($request);
            }
        };
    }
}
