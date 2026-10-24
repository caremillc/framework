<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http;

use Careminate\Foundation\Application;
use Careminate\Http\Kernel;
use Careminate\Http\Middleware\Middleware;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KernelTest extends TestCase
{
    public function testHandleBootsApplication(): void
    {
        $application = new Application();
        $kernel = new Kernel($application);

        $kernel->router()->get(
            '/health',
            static fn (Request $request): Response =>
                new Response(200, body: 'healthy'),
        );

        $response = $kernel->handle(
            new Request('GET', '/health'),
        );

        self::assertTrue($application->isBooted());
        self::assertSame(200, $response->status);
        self::assertSame('healthy', $response->body);
    }

    public function testRepeatedRequestsDoNotRebootApplication(): void
    {
        $application = new Application();
        $bootCount = 0;

        $application->onBoot(
            static function (Application $application) use (
                &$bootCount,
            ): void {
                ++$bootCount;
            },
        );

        $kernel = new Kernel($application);

        $kernel->router()->get(
            '/health',
            static fn (Request $request): Response =>
                new Response(200),
        );

        $kernel->handle(new Request('GET', '/health'));
        $kernel->handle(new Request('GET', '/health'));

        self::assertSame(1, $bootCount);
    }

    public function testMiddlewareWrapsRouterDispatch(): void
    {
        $application = new Application();
        $kernel = new Kernel($application);

        $kernel->middleware(
            new class () implements Middleware {
                public function process(
                    Request $request,
                    Closure $next,
                ): Response {
                    return $next($request)->withHeader(
                        'X-Kernel',
                        'active',
                    );
                }
            }
        );

        $kernel->router()->get(
            '/health',
            static fn (Request $request): Response =>
                new Response(200),
        );

        $response = $kernel->handle(
            new Request('GET', '/health'),
        );

        self::assertSame(
            'active',
            $response->header('X-Kernel'),
        );
    }

    public function testUnknownRouteReturns404(): void
    {
        $kernel = new Kernel(new Application());

        $response = $kernel->handle(
            new Request('GET', '/missing'),
        );

        self::assertSame(404, $response->status);
    }

    public function testTerminationPreventsFurtherRequests(): void
    {
        $application = new Application();
        $kernel = new Kernel($application);

        $kernel->handle(new Request());
        $kernel->terminate();

        self::assertTrue($application->isTerminated());

        $this->expectException(LogicException::class);

        $kernel->handle(new Request());
    }

    public function testFailedBootPreventsDispatch(): void
    {
        $application = new Application();

        $application->onBoot(
            static function (Application $application): void {
                throw new RuntimeException('Boot failed');
            },
        );

        $kernel = new Kernel($application);
        $handlerCalled = false;

        $kernel->router()->get(
            '/',
            static function (Request $request) use (
                &$handlerCalled,
            ): Response {
                $handlerCalled = true;

                return new Response();
            },
        );

        try {
            $kernel->handle(new Request());
            self::fail('Expected boot exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('Boot failed', $exception->getMessage());
        }

        self::assertTrue($application->hasFailed());
        self::assertFalse($handlerCalled);

        $this->expectException(LogicException::class);

        $kernel->handle(new Request());
    }

    public function testMiddlewareCannotBeAddedAfterBoot(): void
    {
        $kernel = new Kernel(new Application());
        $kernel->handle(new Request());

        $this->expectException(LogicException::class);

        $kernel->middleware(
            new class () implements Middleware {
                public function process(
                    Request $request,
                    Closure $next,
                ): Response {
                    return $next($request);
                }
            }
        );
    }

    public function testKernelExposesRouter(): void
    {
        $kernel = new Kernel(new Application());

        self::assertSame(
            $kernel->router(),
            $kernel->router(),
        );
    }

    public function testTerminateDelegatesToApplication(): void
    {
        $application = new Application();
        $terminated = false;

        $application->onTerminate(
            static function (Application $application) use (
                &$terminated,
            ): void {
                $terminated = true;
            },
        );

        $kernel = new Kernel($application);
        $kernel->terminate();

        self::assertTrue($terminated);
        self::assertTrue($application->isTerminated());
    }

    public function testMiddlewareCannotBeAddedAfterTermination(): void
    {
        $kernel = new Kernel(new Application());
        $kernel->terminate();

        $this->expectException(LogicException::class);

        $kernel->middleware(
            new class () implements Middleware {
                public function process(
                    Request $request,
                    Closure $next,
                ): Response {
                    return $next($request);
                }
            }
        );
    }
}
