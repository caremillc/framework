<?php

declare (strict_types=1);

namespace Careminate\Tests\Integration;

use Careminate\Foundation\Framework;
use PHPUnit\Framework\TestCase;

final class ConsoleAboutEntrypointTest extends TestCase
{
    public function testDefaultAboutShowsTable(): void
    {
        [$code, $stdout, $stderr] = self::invoke(['about']);

        self::assertSame(0, $code, $stderr);
        self::assertSame('', $stderr);

        self::assertStringContainsString(
            'Careminate runtime information',
            $stdout,
        );

        self::assertStringContainsString(
            Framework::version(),
            $stdout,
        );

        self::assertStringContainsString(
            PHP_VERSION,
            $stdout,
        );
    }

    public function testExplicitTableMatchesDefault(): void
    {
        [$firstCode, $firstOutput, $firstError] = self::invoke(
            ['about'],
        );

        [$secondCode, $secondOutput, $secondError] = self::invoke(
            ['about', '--format=table'],
        );

        self::assertSame(0, $firstCode, $firstError);
        self::assertSame(0, $secondCode, $secondError);

        self::assertSame(
            $firstOutput,
            $secondOutput,
        );

        self::assertSame('', $secondError);
    }

    public function testJsonAboutReportsRuntimeFacts(): void
    {
        [$code, $stdout, $stderr] = self::invoke(
            ['about', '--format=json'],
        );

        self::assertSame(0, $code, $stderr);
        self::assertSame('', $stderr);

        $data = json_decode(
            $stdout,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($data);

        // Narrow the decoded JSON structures from mixed to arrays.
        $framework = $data['framework'] ?? null;
        $runtime   = $data['runtime'] ?? null;

        self::assertIsArray(
            $framework,
            'The framework JSON property must be an object.',
        );

        self::assertIsArray(
            $runtime,
            'The runtime JSON property must be an object.',
        );

        self::assertSame(
            'Careminate',
            $framework['name'] ?? null,
        );

        self::assertSame(
            Framework::version(),
            $framework['version'] ?? null,
        );

        self::assertSame(
            PHP_VERSION,
            $runtime['php_version'] ?? null,
        );

        self::assertSame(
            PHP_OS_FAMILY,
            $runtime['os_family'] ?? null,
        );
    }

    public function testUnknownFormatReturnsExit64(): void
    {
        [$code, $stdout, $stderr] = self::invoke(
            ['about', '--format=xml'],
        );

        self::assertSame(64, $code, $stderr);
        self::assertSame('', $stdout);

        self::assertStringContainsString(
            'Usage: php careminate about',
            $stderr,
        );
    }

    public function testExtraOptionsReturnExit64(): void
    {
        [$code, $stdout, $stderr] = self::invoke(
            ['about', '--format=json', '--format=json'],
        );

        self::assertSame(64, $code, $stderr);
        self::assertSame('', $stdout);

        self::assertStringContainsString(
            'Invalid about arguments.',
            $stderr,
        );
    }

    public function testGeneralHelpIncludesAbout(): void
    {
        [$code, $stdout, $stderr] = self::invoke(['--help']);

        self::assertSame(0, $code, $stderr);
        self::assertStringContainsString('about', $stdout);
        self::assertSame('', $stderr);
    }

    public function testHelpAboutIsAvailable(): void
    {
        [$code, $stdout, $stderr] = self::invoke(
            ['help', 'about'],
        );

        self::assertSame(0, $code, $stderr);

        self::assertStringContainsString(
            'php careminate about',
            $stdout,
        );

        self::assertStringContainsString(
            'Show framework and PHP runtime information',
            $stdout,
        );
    }

    public function testVersionCommandRemainsUnchanged(): void
    {
        [$code, $stdout, $stderr] = self::invoke(
            ['--version'],
        );

        self::assertSame(0, $code, $stderr);

        self::assertSame(
            'Careminate ' . Framework::version() . "\n",
            $stdout,
        );

        self::assertSame('', $stderr);
    }

    public function testRouteListCommandRemainsAvailable(): void
    {
        [$code, $stdout, $stderr] = self::invoke(
            ['route:list', '--format=json'],
        );

        self::assertSame(0, $code, $stderr);
        self::assertSame("[]\n", $stdout);
        self::assertSame('', $stderr);
    }

    public function testEntrypointWorksOutsideRepositoryDirectory(): void
    {
        [$code, $stdout, $stderr] = self::invoke(
            ['about', '--format=json'],
            sys_get_temp_dir(),
        );

        self::assertSame(0, $code, $stderr);
        self::assertSame('', $stderr);

        self::assertStringContainsString(
            '"framework"',
            $stdout,
        );

        self::assertStringContainsString(
            Framework::version(),
            $stdout,
        );
    }

    public function testEnvironmentSecretIsNotExposedInEitherFormat(): void
    {
        $name     = 'CAREMINATE_ABOUT_TEST_SECRET';
        $previous = getenv($name);
        $secret   = 'CAREM_PRIVATE_SUBPROCESS_7826';

        putenv($name . '=' . $secret);

        try {
            foreach (
                [
                    ['about'],
                    ['about', '--format=json'],
                ] as $arguments
            ) {
                [$code, $stdout, $stderr] = self::invoke(
                    $arguments,
                );

                self::assertSame(0, $code, $stderr);

                self::assertStringNotContainsString(
                    $secret,
                    $stdout,
                );

                self::assertStringNotContainsString(
                    $secret,
                    $stderr,
                );
            }
        } finally {
            putenv(
                $previous === false
                    ? $name
                    : $name . '=' . $previous,
            );
        }
    }

    /**
     * Execute the CLI using the current PHP executable.
     *
     * @param list<string> $arguments
     * @return array{int, string, string}
     */
    private static function invoke(
        array $arguments,
        ?string $workingDirectory = null,
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
            $workingDirectory ?? $root,
            null,
            ['bypass_shell' => true],
        );

        if (! is_resource($process)) {
            self::fail(
                'Unable to start Careminate CLI subprocess.',
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

        return [$exitCode, $stdout, $stderr];
    }
}
