<?php

declare (strict_types=1);

namespace Careminate\Tests\Integration;

use Careminate\Foundation\Framework;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsoleEntrypointIntegrationTest extends TestCase
{
    /**
     * @return iterable<string, array{
     *     list<string>, int, string, string
     * }>
     */
    public static function commandCases(): iterable
    {
        yield 'no arguments'              => [
            [], 0, 'Usage:', '',
        ];

        yield 'help flag'                 => [
            ['--help'], 0, 'php careminate', '',
        ];

        yield 'version flag'              => [
            ['--version'],
            0,
            'Careminate ' . Framework::version(),
            '',
        ];

        yield 'version command'           => [
            ['version'],
            0,
            'Careminate ' . Framework::version(),
            '',
        ];

        yield 'unknown command'           => [
            ['unknown'],
            64,
            '',
            'Unknown console command.',
        ];

        yield 'invalid version arguments' => [
            ['--version', 'extra'],
            64,
            '',
            'accepts no arguments',
        ];

        yield 'unknown help topic'        => [
            ['help', 'missing'],
            64,
            '',
            'Unknown help topic.',
        ];
    }

    /** @param list<string> $args */
    #[DataProvider('commandCases')]
    public function testEntrypointRoutesOutputAndExitCode(
        array $args,
        int $expectedExit,
        string $expectedStdout,
        string $expectedStderr,
    ): void {
        [$exitCode, $stdout, $stderr] = self::invoke($args);

        self::assertSame(
            $expectedExit,
            $exitCode,
            sprintf(
                "Careminate CLI subprocess failed.\n"
                . "Expected exit: %d\n"
                . "Actual exit: %d\n"
                . "STDOUT:\n%s\n"
                . "STDERR:\n%s\n",
                $expectedExit,
                $exitCode,
                $stdout,
                $stderr,
            ),
        );
        self::assertStringContainsString($expectedStdout, $stdout);
        self::assertStringContainsString($expectedStderr, $stderr);

        if ($expectedExit === 0) {
            self::assertSame('', $stderr);
        } else {
            self::assertSame('', $stdout);
        }
    }

    public function testEntrypointWorksOutsideTheFrameworkDirectory(): void
    {
        [$exitCode, $stdout, $stderr] = self::invoke(
            ['--version'],
            sys_get_temp_dir(),
        );

        self::assertSame(0, $exitCode);
        self::assertSame(
            'Careminate ' . Framework::version() . "\n",
            $stdout,
        );
        self::assertSame('', $stderr);
    }

    public function testUnknownCommandIsNotEchoedToStderr(): void
    {
        [$exitCode, $stdout, $stderr] = self::invoke([
            "unknown\nPRIVATE\e[31m",
        ]);

        self::assertSame(64, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringNotContainsString('PRIVATE', $stderr);
        self::assertStringNotContainsString("\e", $stderr);
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string, string}
     */


    /**
     * Execute the real Careminate CLI in a separate PHP process.
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

        self::assertFileExists(
            $entrypoint,
            'Careminate CLI executable is missing: '
            . $entrypoint,
        );

        $command = [
            PHP_BINARY,
            $entrypoint,
            ...$arguments,
        ];

        /** @var array<int, resource> $pipes */
        $pipes = [];

        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory ?? $root,
            null,
            [
                'bypass_shell' => true,
            ],
        );

        if (! is_resource($process)) {
            self::fail(
                'Unable to launch the Careminate CLI subprocess.',
            );
        }

        // The CLI does not require standard input.
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        self::assertIsString(
            $stdout,
            'Failed to read CLI standard output.',
        );

        self::assertIsString(
            $stderr,
            'Failed to read CLI standard error.',
        );

        // Required tuple:
        // [process exit code, stdout, stderr]
        return [
            $exitCode,
            $stdout,
            $stderr,
        ];
    }


}
