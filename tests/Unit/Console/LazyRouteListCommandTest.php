<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Console;

use Careminate\Console\ConsoleApplication;
use Careminate\Console\LazyRouteListCommand;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LazyRouteListCommandTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'unrecognized format' => [
            ['--format=yaml'],
        ];

        yield 'format without value' => [
            ['--format'],
        ];

        yield 'separate format value' => [
            ['--format', 'json'],
        ];

        yield 'duplicate option' => [
            ['--format=json', '--format=json'],
        ];

        yield 'mixed formats' => [
            ['--format=json', '--format=table'],
        ];

        yield 'unexpected flag' => [
            ['--all'],
        ];

        yield 'unexpected positional' => [
            ['unexpected'],
        ];

        yield 'terminal escape and secret' => [
            ["\e[31mPRIVATE\n"],
        ];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('invalidOptions')]
    public function testInvalidArgumentsNeverRunRouteLoader(
        array $arguments,
    ): void {
        $calls = 0;

        $command = new LazyRouteListCommand(
            static function () use (&$calls): Router {
                ++$calls;

                throw new RuntimeException(
                    'SECRET_ROUTE_BOOTSTRAP_FAILURE',
                );
            },
        );

        $result = $command($arguments);

        self::assertSame(0, $calls);
        self::assertSame(64, $result->exitCode);
        self::assertSame('', $result->stdout);

        self::assertStringContainsString(
            'Usage:',
            $result->stderr,
        );

        self::assertStringNotContainsString(
            'PRIVATE',
            $result->stderr,
        );

        self::assertStringNotContainsString(
            'SECRET_',
            $result->stderr,
        );

        self::assertStringNotContainsString(
            "\e",
            $result->stderr,
        );
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function validOptions(): iterable
    {
        yield 'default table' => [
            [],
            'METHOD',
        ];

        yield 'explicit table' => [
            ['--format=table'],
            'METHOD',
        ];

        yield 'JSON format' => [
            ['--format=json'],
            '"method":"GET"',
        ];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('validOptions')]
    public function testValidArgumentsLoadRoutesExactlyOnce(
        array $arguments,
        string $expectedOutput,
    ): void {
        $loads = 0;

        $command = new LazyRouteListCommand(
            static function () use (&$loads): Router {
                ++$loads;

                return self::router();
            },
        );

        $result = $command($arguments);

        self::assertSame(1, $loads);
        self::assertSame(0, $result->exitCode);
        self::assertSame('', $result->stderr);

        self::assertStringContainsString(
            $expectedOutput,
            $result->stdout,
        );
    }

    public function testRepeatedInvocationsUseFreshRouteSnapshots(): void
    {
        $loads = 0;

        $command = new LazyRouteListCommand(
            static function () use (&$loads): Router {
                ++$loads;

                return self::router();
            },
        );

        $first = $command(['--format=json']);
        $second = $command(['--format=json']);

        self::assertSame(2, $loads);
        self::assertSame($first->stdout, $second->stdout);
        self::assertSame(0, $second->exitCode);
    }

    public function testLoaderExceptionsRemainForEntrypointErrorBoundary(): void
    {
        $command = new LazyRouteListCommand(
            static function (): Router {
                throw new RuntimeException(
                    'DO_NOT_EXPOSE_PRIVATE_PATH',
                );
            },
        );

        $this->expectException(RuntimeException::class);

        $this->expectExceptionMessage(
            'DO_NOT_EXPOSE_PRIVATE_PATH',
        );

        $command([]);
    }

    public function testRouteListingNeverDispatchesRouteHandlers(): void
    {
        $handlerCalls = 0;
        $router = new Router();

        $router->get(
            '/health',
            static function (
                Request $request,
            ) use (&$handlerCalls): Response {
                ++$handlerCalls;

                return new Response(200);
            },
        );

        $command = new LazyRouteListCommand(
            static fn (): Router => $router,
        );

        $result = $command([]);

        self::assertSame(0, $result->exitCode);
        self::assertSame(0, $handlerCalls);
        self::assertFalse($router->isFrozen());
    }

    public function testCommandIntegratesWithExistingDispatcher(): void
    {
        $application = new ConsoleApplication();

        $command = new LazyRouteListCommand(
            static fn (): Router => self::router(),
        );

        $application->register(
            'route:list',
            'List registered HTTP routes',
            $command(...),
        );

        $result = $application->run([
            'route:list',
            '--format=json',
        ]);

        self::assertSame(0, $result->exitCode);

        self::assertStringContainsString(
            '"/health"',
            $result->stdout,
        );

        self::assertSame('', $result->stderr);
    }

    public function testInvalidInvocationDoesNotPoisonLaterInvocation(): void
    {
        $loads = 0;

        $command = new LazyRouteListCommand(
            static function () use (&$loads): Router {
                ++$loads;

                return self::router();
            },
        );

        self::assertSame(
            64,
            $command(['--nope'])->exitCode,
        );

        self::assertSame(0, $loads);

        self::assertSame(
            0,
            $command([])->exitCode,
        );

        self::assertSame(1, $loads);
    }

    private static function router(): Router
    {
        $router = new Router();

        $router->get(
            '/health',
            static fn (Request $request): Response =>
                new Response(200),
            [],
            'health',
        );

        return $router;
    }
}
