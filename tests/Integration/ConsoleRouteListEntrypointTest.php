<?php

declare(strict_types=1);

namespace Careminate\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ConsoleRouteListEntrypointTest extends TestCase
{
    public function testGeneralHelpIncludesRouteList(): void
    {
        [$code, $out, $err] = self::invoke(['--help']);

        self::assertSame(0, $code, $err);
        self::assertStringContainsString('route:list', $out);
        self::assertSame('', $err);
    }

    public function testBareFrameworkReportsNoRoutes(): void
    {
        [$code, $out, $err] = self::invoke(['route:list']);

        self::assertSame(0, $code, $err);
        self::assertSame(
            "No registered routes.\n",
            $out,
        );
        self::assertSame('', $err);
    }

    public function testBareFrameworkJsonIsEmptyArray(): void
    {
        [$code, $out, $err] = self::invoke([
            'route:list',
            '--format=json',
        ]);

        self::assertSame(0, $code, $err);
        self::assertSame("[]\n", $out);
        self::assertSame('', $err);
    }

    public function testRouteListRejectsUnknownOptions(): void
    {
        [$code, $out, $err] = self::invoke([
            'route:list',
            '--format=xml',
        ]);

        self::assertSame(64, $code, $err);
        self::assertSame('', $out);

        self::assertStringContainsString(
            'Usage:',
            $err,
        );
    }

    public function testCommandHelpIsAvailable(): void
    {
        [$code, $out, $err] = self::invoke([
            'help',
            'route:list',
        ]);

        self::assertSame(0, $code, $err);

        self::assertStringContainsString(
            'List registered HTTP routes',
            $out,
        );

        self::assertSame('', $err);
    }

    public function testExistingVersionCommandIsUnaffected(): void
    {
        [$code, $out, $err] = self::invoke(['--version']);

        self::assertSame(0, $code, $err);
        self::assertStringStartsWith(
            'Careminate ',
            $out,
        );
        self::assertSame('', $err);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private static function invoke(
        array $arguments,
    ): array {
        $root = dirname(__DIR__, 2);

        $entrypoint = $root
            . DIRECTORY_SEPARATOR
            . 'careminate';

        self::assertFileExists($entrypoint);

        /** @var array<int, resource> $pipes */
        $pipes = [];

        $process = proc_open(
            [
                PHP_BINARY,
                $entrypoint,
                ...$arguments,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true],
        );

        if (! is_resource($process)) {
            self::fail(
                'Cannot start Careminate CLI.',
            );
        }

        // No interactive input is required.
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($process);

        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return [
            $code,
            $stdout,
            $stderr,
        ];
    }
}
