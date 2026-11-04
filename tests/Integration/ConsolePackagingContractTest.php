<?php

declare(strict_types=1);

namespace Careminate\Tests\Integration;

use Careminate\Foundation\Framework;
use PHPUnit\Framework\TestCase;

final class ConsolePackagingContractTest extends TestCase
{
    public function testComposerRegistersCareminateBinary(): void
    {
        $manifest = file_get_contents(
            self::root() . '/composer.json',
        );

        self::assertIsString($manifest);

        $data = json_decode(
            $manifest,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($data);

        self::assertSame(
            ['careminate'],
            $data['bin'] ?? null,
        );
    }

    public function testLauncherHasUnixShebangAndNoUtf8Bom(): void
    {
        $source = file_get_contents(
            self::root() . '/careminate',
        );

        self::assertIsString($source);

        self::assertStringStartsWith(
            "#!/usr/bin/env php\n<?php\n",
            $source,
        );

        self::assertFalse(
            str_starts_with(
                $source,
                "\xEF\xBB\xBF",
            ),
        );
    }

    public function testDefaultInvocationShowsUsage(): void
    {
        [$code, $stdout, $stderr] = self::invoke([]);

        self::assertSame(0, $code, $stderr);
        self::assertStringContainsString(
            'Usage:',
            $stdout,
        );
        self::assertSame('', $stderr);
    }

    public function testVersionUsesFrameworkVersion(): void
    {
        [$code, $stdout, $stderr] = self::invoke([
            '--version',
        ]);

        self::assertSame(0, $code, $stderr);

        self::assertSame(
            'Careminate ' . Framework::version() . "\n",
            $stdout,
        );

        self::assertSame('', $stderr);
    }

    public function testHelpAdvertisesAboutAndRouteList(): void
    {
        [$code, $stdout, $stderr] = self::invoke([
            '--help',
        ]);

        self::assertSame(0, $code, $stderr);

        self::assertStringContainsString(
            'route:list',
            $stdout,
        );

        self::assertStringContainsString(
            'about',
            $stdout,
        );

        self::assertSame('', $stderr);
    }

    public function testAboutJsonContainsOnlyPublicRuntimeSections(): void
    {
        [$code, $stdout, $stderr] = self::invoke([
            'about',
            '--format=json',
        ]);

        self::assertSame(0, $code, $stderr);
        self::assertSame('', $stderr);

        $data = json_decode(
            $stdout,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($data);

        self::assertSame(
            ['framework', 'runtime', 'extensions'],
            array_keys($data),
        );

        $framework = $data['framework'] ?? null;

        self::assertIsArray($framework);

        self::assertSame(
            Framework::version(),
            $framework['version'] ?? null,
        );
    }

    public function testStandaloneFrameworkRouteListIsEmptyJson(): void
    {
        [$code, $stdout, $stderr] = self::invoke([
            'route:list',
            '--format=json',
        ]);

        self::assertSame(0, $code, $stderr);
        self::assertSame("[]\n", $stdout);
        self::assertSame('', $stderr);
    }

    public function testInvalidRouteFormatReturnsUsageExitCode(): void
    {
        [$code, $stdout, $stderr] = self::invoke([
            'route:list',
            '--format=xml',
        ]);

        self::assertSame(64, $code, $stderr);
        self::assertSame('', $stdout);

        self::assertStringContainsString(
            'Usage:',
            $stderr,
        );
    }

    public function testUnknownCommandDoesNotEchoTerminalInput(): void
    {
        [$code, $stdout, $stderr] = self::invoke([
            "missing\e[31mPRIVATE\n",
        ]);

        self::assertSame(64, $code, $stderr);
        self::assertSame('', $stdout);

        self::assertStringNotContainsString(
            'PRIVATE',
            $stderr,
        );

        self::assertStringNotContainsString(
            "\e",
            $stderr,
        );
    }

    public function testLauncherWorksFromDifferentWorkingDirectory(): void
    {
        [$code, $stdout, $stderr] = self::invoke(
            ['--version'],
            sys_get_temp_dir(),
        );

        self::assertSame(0, $code, $stderr);

        self::assertSame(
            'Careminate ' . Framework::version() . "\n",
            $stdout,
        );

        self::assertSame('', $stderr);
    }

    public function testMissingAutoloaderReturnsConfigurationExitCode(): void
    {
        $directory = tempnam(
            sys_get_temp_dir(),
            'caremi_cli_',
        );

        self::assertIsString($directory);
        self::assertTrue(unlink($directory));
        self::assertTrue(mkdir($directory, 0o700));

        $entrypoint = $directory
            . DIRECTORY_SEPARATOR
            . 'careminate';

        try {
            $source = file_get_contents(
                self::root() . '/careminate',
            );

            self::assertIsString($source);

            self::assertSame(
                strlen($source),
                file_put_contents(
                    $entrypoint,
                    $source,
                ),
            );

            [$code, $stdout, $stderr] = self::invoke(
                [],
                $directory,
                $entrypoint,
            );

            self::assertSame(78, $code, $stderr);
            self::assertSame('', $stdout);

            self::assertSame(
                "Careminate: Composer autoload missing; run composer install.\n",
                $stderr,
            );
        } finally {
            if (is_file($entrypoint)) {
                unlink($entrypoint);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string, string}
     */
    private static function invoke(
        array $arguments,
        ?string $workingDirectory = null,
        ?string $entrypoint = null,
    ): array {
        $root = self::root();

        $binary = $entrypoint
            ?? $root . DIRECTORY_SEPARATOR . 'careminate';

        self::assertFileExists($binary);

        /** @var array<int, resource> $pipes */
        $pipes = [];

        $process = proc_open(
            [
                PHP_BINARY,
                $binary,
                ...$arguments,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory ?? $root,
            null,
            ['bypass_shell' => true],
        );

        if (! is_resource($process)) {
            self::fail(
                'Unable to launch Careminate CLI subprocess.',
            );
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return [
            $exitCode,
            $stdout,
            $stderr,
        ];
    }
}
