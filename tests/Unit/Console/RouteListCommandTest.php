<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Console;

use Careminate\Console\ConsoleApplication;
use Careminate\Console\RouteListCommand;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouteListCommandTest extends TestCase
{
    public function testEmptyRouterHasHonestTextOutput(): void
    {
        $result = (new RouteListCommand(new Router()))([]);

        self::assertSame(0, $result->exitCode);
        self::assertSame(
            "No registered routes.\n",
            $result->stdout,
        );
        self::assertSame('', $result->stderr);
    }

    public function testEmptyRouterHasJsonArrayOutput(): void
    {
        $result = (new RouteListCommand(new Router()))(
            ['--format=json'],
        );

        self::assertSame(0, $result->exitCode);
        self::assertSame("[]\n", $result->stdout);
        self::assertSame('', $result->stderr);
    }

    public function testExplicitTableFormatMatchesDefault(): void
    {
        $command = new RouteListCommand(
            self::router(),
        );

        self::assertSame(
            $command([])->stdout,
            $command(['--format=table'])->stdout,
        );
    }

    public function testTableHasSortedMethodsPathsAndNames(): void
    {
        $result = (new RouteListCommand(self::router()))([]);

        self::assertSame(0, $result->exitCode);

        self::assertSame(
            "METHOD  PATH     NAME\n"
            . "GET     /a       a.index\n"
            . "POST    /a       -\n"
            . "GET     /z/{id}  z.show\n",
            $result->stdout,
        );
    }

    public function testJsonHasStableShapeAndOrder(): void
    {
        $result = (new RouteListCommand(self::router()))(
            ['--format=json'],
        );

        self::assertSame(0, $result->exitCode);

        self::assertSame(
            [
                [
                    'method' => 'GET',
                    'path' => '/a',
                    'name' => 'a.index',
                ],
                [
                    'method' => 'POST',
                    'path' => '/a',
                    'name' => null,
                ],
                [
                    'method' => 'GET',
                    'path' => '/z/{id}',
                    'name' => 'z.show',
                ],
            ],
            json_decode(
                $result->stdout,
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );

        self::assertSame('', $result->stderr);
    }

    public function testListingDoesNotFreezeOrRunRouter(): void
    {
        $called = 0;
        $router = new Router();

        $router->get(
            '/a',
            static function (Request $request) use (&$called): Response {
                ++$called;

                return new Response(200);
            },
        );

        $result = (new RouteListCommand($router))([]);

        self::assertSame(0, $result->exitCode);
        self::assertSame(0, $called);
        self::assertFalse($router->isFrozen());
    }

    public function testFrozenRouterCanStillBeInspected(): void
    {
        $router = self::router();
        $router->freeze();

        $result = (new RouteListCommand($router))(
            ['--format=json'],
        );

        self::assertSame(0, $result->exitCode);
        self::assertTrue($router->isFrozen());
    }

    public function testRepeatedInvocationIsIdentical(): void
    {
        $command = new RouteListCommand(
            self::router(),
        );

        self::assertSame(
            $command([])->stdout,
            $command([])->stdout,
        );

        self::assertSame(
            $command(['--format=json'])->stdout,
            $command(['--format=json'])->stdout,
        );
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'unknown format' => [
            ['--format=yaml'],
        ];

        yield 'unknown flag' => [
            ['--all'],
        ];

        yield 'extra positional' => [
            ['extra'],
        ];

        yield 'duplicate format' => [
            ['--format=json', '--format=json'],
        ];

        yield 'two formats' => [
            ['--format=table', '--format=json'],
        ];

        yield 'separate format token' => [
            ['--format', 'json'],
        ];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('invalidArguments')]
    public function testInvalidArgumentsReturnUsageError(
        array $arguments,
    ): void {
        $result = (new RouteListCommand(self::router()))(
            $arguments,
        );

        self::assertSame(64, $result->exitCode);
        self::assertSame('', $result->stdout);

        self::assertStringContainsString(
            'Usage:',
            $result->stderr,
        );
    }

    public function testCommandRegistersWithExistingDispatcher(): void
    {
        $app = new ConsoleApplication();
        $command = new RouteListCommand(self::router());

        $app->register(
            'route:list',
            'List registered HTTP routes',
            $command(...),
        );

        $result = $app->run([
            'route:list',
            '--format=json',
        ]);

        self::assertSame(0, $result->exitCode);

        self::assertStringContainsString(
            '"a.index"',
            $result->stdout,
        );

        self::assertSame('', $result->stderr);
    }

    public function testCommandAppearsInHelpAfterRegistration(): void
    {
        $app = new ConsoleApplication();
        $command = new RouteListCommand(self::router());

        $app->register(
            'route:list',
            'List registered HTTP routes',
            $command(...),
        );

        self::assertStringContainsString(
            'route:list',
            $app->run(['help'])->stdout,
        );

        self::assertStringContainsString(
            'List registered HTTP routes',
            $app->run(['help', 'route:list'])->stdout,
        );
    }

    private static function router(): Router
    {
        $router = new Router();

        $handler = static fn (Request $request): Response =>
            new Response(200);

        $router->get(
            '/z/{id}',
            $handler,
            [],
            'z.show',
        );

        $router->post('/a', $handler);

        $router->get(
            '/a',
            $handler,
            [],
            'a.index',
        );

        return $router;
    }
}
