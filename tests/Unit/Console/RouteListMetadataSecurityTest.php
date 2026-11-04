<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Console;

use Careminate\Console\RouteListCommand;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouteListMetadataSecurityTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function unsafeRouteMetadata(): iterable
    {
        yield 'malformed UTF8 table' => [
            "/bad\xFF",
            [],
        ];

        yield 'malformed UTF8 JSON' => [
            "/bad\xFF",
            ['--format=json'],
        ];

        yield 'bidirectional override table' => [
            "/bad\u{202E}txt",
            [],
        ];

        yield 'bidirectional override JSON' => [
            "/bad\u{202E}txt",
            ['--format=json'],
        ];

        yield 'invisible formatting table' => [
            "/bad\u{2066}txt",
            [],
        ];

        yield 'invisible formatting JSON' => [
            "/bad\u{2066}txt",
            ['--format=json'],
        ];

        yield 'C1 control table' => [
            "/bad\u{0085}txt",
            [],
        ];

        yield 'C1 control JSON' => [
            "/bad\u{0085}txt",
            ['--format=json'],
        ];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('unsafeRouteMetadata')]
    public function testUnsafeRouteMetadataIsNotRendered(
        string $path,
        array $arguments,
    ): void {
        $router = new Router();

        $router->get(
            $path,
            static fn (Request $request): Response =>
                new Response(200),
        );

        $result = (new RouteListCommand($router))(
            $arguments,
        );

        self::assertSame(70, $result->exitCode);
        self::assertSame('', $result->stdout);

        self::assertSame(
            "Cannot display route metadata safely.\n",
            $result->stderr,
        );

        self::assertFalse($router->isFrozen());
    }

    public function testNormalUnicodeRouteIsPreserved(): void
    {
        $router = new Router();

        $router->get(
            '/café',
            static fn (Request $request): Response =>
                new Response(200),
        );

        $table = (new RouteListCommand($router))([]);
        $json = (new RouteListCommand($router))(
            ['--format=json'],
        );

        self::assertSame(0, $table->exitCode);

        self::assertStringContainsString(
            '/café',
            $table->stdout,
        );

        self::assertSame(0, $json->exitCode);

        self::assertStringContainsString(
            '/café',
            $json->stdout,
        );
    }

    public function testInvalidOptionsAreRejectedBeforeReadingMetadata(): void
    {
        $router = new Router();

        $router->get(
            "/unsafe\u{202E}path",
            static fn (Request $request): Response =>
                new Response(200),
        );

        $result = (new RouteListCommand($router))(
            ['--format=xml'],
        );

        self::assertSame(64, $result->exitCode);
        self::assertSame('', $result->stdout);

        self::assertStringContainsString(
            'Usage:',
            $result->stderr,
        );
    }

    public function testSafeEmptyRouterStillUsesExistingContract(): void
    {
        $command = new RouteListCommand(
            new Router(),
        );

        self::assertSame(
            "No registered routes.\n",
            $command([])->stdout,
        );

        self::assertSame(
            "[]\n",
            $command(['--format=json'])->stdout,
        );
    }
}
