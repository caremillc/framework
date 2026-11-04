<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Console;

use Careminate\Console\AboutCommand;
use Careminate\Console\ConsoleApplication;
use Careminate\Foundation\Framework;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AboutCommandTest extends TestCase
{
    public function testDefaultTableContainsFrameworkAndRuntimeFacts(): void
    {
        $result = (new AboutCommand())([]);

        self::assertSame(0, $result->exitCode);
        self::assertSame('', $result->stderr);

        self::assertStringContainsString(
            'Careminate runtime information',
            $result->stdout,
        );

        self::assertStringContainsString(
            Framework::version(),
            $result->stdout,
        );

        self::assertStringContainsString(
            PHP_VERSION,
            $result->stdout,
        );

        self::assertStringContainsString(
            PHP_SAPI,
            $result->stdout,
        );
    }

    public function testExplicitTableMatchesDefault(): void
    {
        $command = new AboutCommand();

        self::assertSame(
            $command([])->stdout,
            $command(['--format=table'])->stdout,
        );
    }

    public function testJsonHasOnlyDocumentedTopLevelAndNestedKeys(): void
    {
        $data = self::jsonData();

        self::assertSame(
            ['framework', 'runtime', 'extensions'],
            array_keys($data),
        );

        self::assertSame(
            ['name', 'version'],
            array_keys($data['framework']),
        );

        self::assertSame(
            [
                'php_version',
                'minimum_php_version',
                'supported',
                'sapi',
                'os_family',
                'architecture_bits',
            ],
            array_keys($data['runtime']),
        );

        self::assertSame(
            ['json', 'mbstring', 'pdo'],
            array_keys($data['extensions']),
        );
    }

    public function testJsonReportsActualRuntimeWithoutHardcodedVersion(): void
    {
        $data = self::jsonData();

        self::assertSame(
            'Careminate',
            $data['framework']['name'],
        );

        self::assertSame(
            Framework::version(),
            $data['framework']['version'],
        );

        self::assertSame(
            PHP_VERSION,
            $data['runtime']['php_version'],
        );

        self::assertSame(
            PHP_SAPI,
            $data['runtime']['sapi'],
        );

        self::assertSame(
            PHP_OS_FAMILY,
            $data['runtime']['os_family'],
        );

        self::assertSame(
            PHP_INT_SIZE * 8,
            $data['runtime']['architecture_bits'],
        );
    }

    public function testJsonReportsMinimumSupportedPhpVersion(): void
    {
        $data = self::jsonData();

        self::assertSame(
            Framework::MINIMUM_PHP_VERSION,
            $data['runtime']['minimum_php_version'],
        );
    }


    public function testRuntimeSupportMatchesFrameworkPolicy(): void
    {
        $data = self::jsonData();

        self::assertSame(
            Framework::supportsRuntime(),
            $data['runtime']['supported'],
        );
    }


    public function testExtensionFlagsReflectActualLoadedExtensions(): void
    {
        $data = self::jsonData();

        foreach (['json', 'mbstring', 'pdo'] as $extension) {
            self::assertSame(
                extension_loaded($extension),
                $data['extensions'][$extension],
            );
        }
    }


    public function testExtensionFlagsAreBooleans(): void
    {
        $result = (new AboutCommand())(['--format=json']);

        self::assertSame(0, $result->exitCode);

        $decoded = json_decode(
            $result->stdout,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($decoded);

        $extensions = $decoded['extensions'] ?? null;

        self::assertIsArray($extensions);

        foreach (['json', 'mbstring', 'pdo'] as $name) {
            self::assertArrayHasKey($name, $extensions);
            self::assertIsBool($extensions[$name]);
        }
    }


    public function testJsonAndTableAgreeOnVersionAndArchitecture(): void
    {
        $command = new AboutCommand();
        $table = $command([])->stdout;
        $data = self::jsonData();

        self::assertStringContainsString(
            $data['framework']['version'],
            $table,
        );

        self::assertStringContainsString(
            $data['runtime']['architecture_bits'] . '-bit',
            $table,
        );
    }

    public function testRepeatedCallsAreIdentical(): void
    {
        $command = new AboutCommand();

        self::assertSame(
            $command([])->stdout,
            $command([])->stdout,
        );

        self::assertSame(
            $command(['--format=json'])->stdout,
            $command(['--format=json'])->stdout,
        );
    }

    public function testJsonOutputEndsWithExactlyOneNewline(): void
    {
        $result = (new AboutCommand())(['--format=json']);

        self::assertSame(0, $result->exitCode);

        self::assertStringEndsWith(
            "\n",
            $result->stdout,
        );

        self::assertFalse(
            str_ends_with($result->stdout, "\n\n"),
        );
    }

    public function testTableOutputEndsWithExactlyOneNewline(): void
    {
        $result = (new AboutCommand())([]);

        self::assertStringEndsWith(
            "\n",
            $result->stdout,
        );

        self::assertFalse(
            str_ends_with($result->stdout, "\n\n"),
        );
    }

    public function testSuccessfulCommandsNeverWriteStderr(): void
    {
        $command = new AboutCommand();

        foreach (
            [
                [],
                ['--format=table'],
                ['--format=json'],
            ] as $arguments
        ) {
            $result = $command($arguments);

            self::assertSame(0, $result->exitCode);
            self::assertSame('', $result->stderr);
        }
    }

    public function testEnvironmentVariableIsNotDisclosed(): void
    {
        $name = 'CAREMINATE_ABOUT_TEST_SECRET';
        $previous = getenv($name);
        $secret = 'CAREM_SECRET_NOT_FOR_OUTPUT_92871';

        putenv($name . '=' . $secret);

        try {
            $command = new AboutCommand();

            self::assertStringNotContainsString(
                $secret,
                $command([])->stdout,
            );

            self::assertStringNotContainsString(
                $secret,
                $command(['--format=json'])->stdout,
            );
        } finally {
            putenv(
                $previous === false
                    ? $name
                    : $name . '=' . $previous,
            );
        }
    }

    public function testDispatcherHelpAndCommandRegistration(): void
    {
        $app = new ConsoleApplication();
        $command = new AboutCommand();

        $app->register(
            'about',
            'Show safe runtime information',
            $command(...),
        );

        self::assertSame(
            0,
            $app->run(['about'])->exitCode,
        );

        self::assertStringContainsString(
            'about',
            $app->run(['--help'])->stdout,
        );

        self::assertStringContainsString(
            'Show safe runtime information',
            $app->run(['help', 'about'])->stdout,
        );
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'unrecognized format' => [
            ['--format=yaml'],
        ];

        yield 'missing format value' => [
            ['--format'],
        ];

        yield 'separate format tokens' => [
            ['--format', 'json'],
        ];

        yield 'positional argument' => [
            ['extra'],
        ];

        yield 'duplicate format' => [
            ['--format=json', '--format=json'],
        ];

        yield 'two formats' => [
            ['--format=table', '--format=json'],
        ];

        yield 'unknown flag' => [
            ['--verbose'],
        ];

        yield 'nested help option' => [
            ['--help'],
        ];

        yield 'control characters in option' => [
            ["\e[31mPRIVATE\n"],
        ];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('invalidArguments')]
    public function testInvalidArgumentsReturnSafeUsageError(
        array $arguments,
    ): void {
        $result = (new AboutCommand())($arguments);

        self::assertSame(64, $result->exitCode);
        self::assertSame('', $result->stdout);

        self::assertStringContainsString(
            'Usage: php careminate about',
            $result->stderr,
        );

        self::assertStringNotContainsString(
            'PRIVATE',
            $result->stderr,
        );

        self::assertStringNotContainsString(
            "\e",
            $result->stderr,
        );
    }

    /**
     * @return array{
     *     framework: array{name: string, version: string},
     *     runtime: array{
     *         php_version: string,
     *         minimum_php_version: string,
     *         supported: bool,
     *         sapi: string,
     *         os_family: string,
     *         architecture_bits: int
     *     },
     *     extensions: array{json: bool, mbstring: bool, pdo: bool}
     * }
     */
    private static function jsonData(): array
    {
        $result = (new AboutCommand())(['--format=json']);

        self::assertSame(0, $result->exitCode);
        self::assertSame('', $result->stderr);

        $data = json_decode(
            $result->stdout,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($data);

        /** @var array{
         *     framework: array{name: string, version: string},
         *     runtime: array{
         *         php_version: string,
         *         minimum_php_version: string,
         *         supported: bool,
         *         sapi: string,
         *         os_family: string,
         *         architecture_bits: int
         *     },
         *     extensions: array{json: bool, mbstring: bool, pdo: bool}
         * } $data */
        return $data;
    }


}
