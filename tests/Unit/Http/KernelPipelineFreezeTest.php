<?php

declare (strict_types=1);

namespace Careminate\Tests\Unit\Http;

use Careminate\Foundation\Application;
use Careminate\Http\Kernel;
use Careminate\Http\Middleware\Middleware;
use Careminate\Http\Middleware\Pipeline;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KernelPipelineFreezeTest extends TestCase
{
    public function testKernelPipelineStartsMutable(): void
    {
        $pipeline = new Pipeline();

        $kernel = new Kernel(
            new Application(),
            pipeline: $pipeline,
        );

        self::assertFalse($pipeline->isFrozen());
        self::assertFalse($kernel->router()->isFrozen());
    }

    public function testKernelFreezesPipelineOnHandle(): void
    {
        $pipeline = new Pipeline();

        $kernel = new Kernel(
            new Application(),
            pipeline: $pipeline,
        );

        $kernel->handle(new Request());

        self::assertTrue($pipeline->isFrozen());
        self::assertTrue($kernel->router()->isFrozen());
    }

    public function testExternalPipelineReferenceCannotAddAfterHandle(): void
    {
        $pipeline = new Pipeline();

        $kernel = new Kernel(
            new Application(),
            pipeline: $pipeline,
        );

        $kernel->handle(new Request());

        $this->expectException(LogicException::class);

        $pipeline->add($this->passthroughMiddleware());
    }

    public function testKernelRejectsMiddlewareAfterExplicitFreeze(): void
    {
        $pipeline = new Pipeline();
        $pipeline->freeze();

        $kernel = new Kernel(
            new Application(),
            pipeline: $pipeline,
        );

        $this->expectException(LogicException::class);

        $kernel->middleware(
            $this->passthroughMiddleware(),
        );
    }

    public function testBootCallbackCanRegisterMiddleware(): void
    {
        $application = new Application();
        $pipeline    = new Pipeline();

        $kernel = new Kernel(
            $application,
            pipeline: $pipeline,
        );

        $application->onBoot(
            static function (Application $application) use (
                $kernel,
            ): void {
                $kernel->middleware(
                    new class () implements Middleware {
                        public function process(
                            Request $request,
                            Closure $next,
                        ): Response {
                            return $next($request)->withHeader(
                                'X-Boot',
                                'active',
                            );
                        }
                    }
                );
            },
        );

        $response = $kernel->handle(new Request());

        self::assertSame(
            'active',
            $response->header('X-Boot'),
        );
        self::assertTrue($pipeline->isFrozen());
    }

    public function testFailedBootDoesNotFreezePipeline(): void
    {
        $application = new Application();
        $pipeline    = new Pipeline();

        $application->onBoot(
            static function (Application $application): void {
                throw new RuntimeException('Boot failed');
            },
        );

        $kernel = new Kernel(
            $application,
            pipeline: $pipeline,
        );

        try {
            $kernel->handle(new Request());
            self::fail('Expected boot failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Boot failed', $exception->getMessage());
        }

        self::assertFalse($pipeline->isFrozen());
        self::assertFalse($kernel->router()->isFrozen());
        self::assertTrue($application->hasFailed());
    }

    public function testRepeatedRequestsPreserveFrozenConfiguration(): void
    {
        $pipeline = new Pipeline();

        $kernel = new Kernel(
            new Application(),
            pipeline: $pipeline,
        );

        $kernel->middleware($this->passthroughMiddleware());

        $first  = $kernel->handle(new Request());
        $second = $kernel->handle(new Request());

        self::assertSame(404, $first->status);
        self::assertSame(404, $second->status);
        self::assertSame(1, $pipeline->count());
        self::assertTrue($pipeline->isFrozen());
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
