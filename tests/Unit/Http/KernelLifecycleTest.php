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

final class KernelLifecycleTest extends TestCase
{
    public function testNestedRequestIsRejected(): void
    {
        $kernel = new Kernel(new Application());
        $nestedRejected = false;

        $kernel->router()->get(
            '/outer',
            static function (Request $request) use (
                $kernel,
                &$nestedRejected,
            ): Response {
                try {
                    $kernel->handle(
                        new Request('GET', '/inner')
                    );
                } catch (LogicException) {
                    $nestedRejected = true;
                }

                return new Response(200);
            },
        );

        $response = $kernel->handle(
            new Request('GET', '/outer'),
        );

        self::assertSame(200, $response->status);
        self::assertTrue($nestedRejected);
    }

    public function testTerminationDuringDispatchIsRejected(): void
    {
        $kernel = new Kernel(new Application());
        $terminationRejected = false;

        $kernel->router()->get(
            '/terminate',
            static function (Request $request) use (
                $kernel,
                &$terminationRejected,
            ): Response {
                try {
                    $kernel->terminate();
                } catch (LogicException) {
                    $terminationRejected = true;
                }

                return new Response(200);
            },
        );

        $response = $kernel->handle(
            new Request('GET', '/terminate'),
        );

        self::assertSame(200, $response->status);
        self::assertTrue($terminationRejected);
    }

    public function testMiddlewareRegistrationDuringDispatchIsRejected(): void
    {
        $kernel = new Kernel(new Application());
        $registrationRejected = false;

        $kernel->router()->get(
            '/configure',
            function (Request $request) use (
                $kernel,
                &$registrationRejected,
            ): Response {
                try {
                    $kernel->middleware(
                        $this->passthroughMiddleware()
                    );
                } catch (LogicException) {
                    $registrationRejected = true;
                }

                return new Response(200);
            },
        );

        $response = $kernel->handle(
            new Request('GET', '/configure'),
        );

        self::assertSame(200, $response->status);
        self::assertTrue($registrationRejected);
    }

    public function testBootCallbackCanRegisterMiddleware(): void
    {
        $application = new Application();
        $kernel = new Kernel($application);

        $application->onBoot(
            function (Application $application) use (
                $kernel,
            ): void {
                $kernel->middleware(
                    $this->passthroughMiddleware()
                );
            },
        );

        $response = $kernel->handle(new Request());

        self::assertSame(404, $response->status);
    }

    public function testNestedRequestDuringBootIsRejected(): void
    {
        $application = new Application();
        $kernel = new Kernel($application);
        $nestedRejected = false;

        $application->onBoot(
            static function (Application $application) use (
                $kernel,
                &$nestedRejected,
            ): void {
                try {
                    $kernel->handle(new Request());
                } catch (LogicException) {
                    $nestedRejected = true;
                }
            },
        );

        $response = $kernel->handle(new Request());

        self::assertSame(404, $response->status);
        self::assertTrue($nestedRejected);
    }

    public function testTerminationDuringBootIsRejected(): void
    {
        $application = new Application();
        $kernel = new Kernel($application);
        $terminationRejected = false;

        $application->onBoot(
            static function (Application $application) use (
                $kernel,
                &$terminationRejected,
            ): void {
                try {
                    $kernel->terminate();
                } catch (LogicException) {
                    $terminationRejected = true;
                }
            },
        );

        $response = $kernel->handle(new Request());

        self::assertSame(404, $response->status);
        self::assertTrue($terminationRejected);
    }

    public function testKernelCanHandleAfterHandlerException(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/failure',
            static function (Request $request): Response {
                throw new RuntimeException('Handler failed');
            },
        );

        $kernel->router()->get(
            '/health',
            static fn (Request $request): Response =>
                new Response(200),
        );

        try {
            $kernel->handle(
                new Request('GET', '/failure')
            );

            self::fail('Expected handler exception.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Handler failed',
                $exception->getMessage(),
            );
        }

        $response = $kernel->handle(
            new Request('GET', '/health'),
        );

        self::assertSame(200, $response->status);
    }

    public function testFailedBootBlocksFutureRequests(): void
    {
        $application = new Application();

        $application->onBoot(
            static function (Application $application): void {
                throw new RuntimeException('Boot failed');
            },
        );

        $kernel = new Kernel($application);

        try {
            $kernel->handle(new Request());

            self::fail('Expected boot exception.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Boot failed',
                $exception->getMessage(),
            );
        }

        $this->expectException(LogicException::class);

        $kernel->handle(new Request());
    }

    public function testAlreadyTerminatedApplicationRejectsRequest(): void
    {
        $application = new Application();
        $application->terminate();

        $kernel = new Kernel($application);

        $this->expectException(LogicException::class);

        $kernel->handle(new Request());
    }

    public function testTerminationFailurePropagates(): void
    {
        $application = new Application();

        $application->onTerminate(
            static function (Application $application): void {
                throw new RuntimeException(
                    'Termination failed'
                );
            },
        );

        $kernel = new Kernel($application);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Termination failed');

        $kernel->terminate();
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
