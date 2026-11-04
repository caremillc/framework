<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http;

use Careminate\Foundation\Application;
use Careminate\Http\Kernel;
use Careminate\Http\Request;
use Careminate\Http\Response;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KernelFreezeTest extends TestCase
{
    public function testKernelRouterStartsMutable(): void
    {
        $kernel = new Kernel(new Application());

        self::assertFalse($kernel->router()->isFrozen());
    }

    public function testHandleFreezesRouter(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->handle(new Request());

        self::assertTrue($kernel->router()->isFrozen());
    }

    public function testRouteRegistrationAfterHandleIsRejected(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->handle(new Request());

        $this->expectException(LogicException::class);

        $kernel->router()->get(
            '/late',
            static fn (Request $request): Response =>
                new Response(200),
        );
    }

    public function testBootCallbackCanRegisterRoutes(): void
    {
        $application = new Application();
        $kernel = new Kernel($application);

        $application->onBoot(
            static function (Application $application) use (
                $kernel,
            ): void {
                $kernel->router()->get(
                    '/booted',
                    static fn (Request $request): Response =>
                        new Response(200, body: 'ready'),
                );
            },
        );

        $response = $kernel->handle(
            new Request('GET', '/booted'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('ready', $response->body);
        self::assertTrue($kernel->router()->isFrozen());
    }

    public function testFailedBootDoesNotFreezeRouter(): void
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
            self::fail('Expected boot failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Boot failed', $exception->getMessage());
        }

        self::assertFalse($kernel->router()->isFrozen());
        self::assertTrue($application->hasFailed());
    }




    public function testRepeatedRequestsKeepRouterFrozen(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/health',
            static fn (Request $request): Response =>
                new Response(200),
        );

        $first = $kernel->handle(
            new Request('GET', '/health'),
        );

        $second = $kernel->handle(
            new Request('GET', '/health'),
        );

        self::assertSame(200, $first->status);
        self::assertSame(200, $second->status);
        self::assertTrue($kernel->router()->isFrozen());
    }

    public function testExternallyFrozenRouterCanBeUsed(): void
    {
        $router = new \Careminate\Http\Routing\Router();

        $router->get(
            '/health',
            static fn (Request $request): Response =>
                new Response(200),
        );

        $router->freeze();

        $kernel = new Kernel(
            new Application(),
            $router,
        );

        $response = $kernel->handle(
            new Request('GET', '/health'),
        );

        self::assertSame(200, $response->status);
    }
}
