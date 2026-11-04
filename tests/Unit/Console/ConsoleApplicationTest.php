<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Console;

use Careminate\Console\ConsoleApplication;
use Careminate\Console\ConsoleResult;
use Careminate\Foundation\Framework;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsoleApplicationTest extends TestCase
{
    /** @return iterable<string, array{list<string>}> */
    public static function helpInvocations(): iterable
    {
        yield 'no arguments' => [[]];
        yield 'help command' => [['help']];
        yield 'long help flag' => [['--help']];
        yield 'short help flag' => [['-h']];
    }

    /** @param list<string> $arguments */
    #[DataProvider('helpInvocations')]
    public function testBuiltInHelpIsSuccessful(array $arguments): void
    {
        $result = new ConsoleApplication()->run($arguments);

        self::assertSame(0, $result->exitCode);
        self::assertSame('', $result->stderr);
        self::assertStringContainsString('Usage:', $result->stdout);
        self::assertStringContainsString('php careminate', $result->stdout);
        self::assertStringContainsString(Framework::version(), $result->stdout);
        self::assertStringEndsWith("\n", $result->stdout);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function versionInvocations(): iterable
    {
        yield 'version command' => [['version']];
        yield 'long version flag' => [['--version']];
        yield 'short version flag' => [['-V']];
    }

    /** @param list<string> $arguments */
    #[DataProvider('versionInvocations')]
    public function testVersionUsesFrameworkVersion(array $arguments): void
    {
        $result = new ConsoleApplication()->run($arguments);

        self::assertSame(0, $result->exitCode);
        self::assertSame(
            'Careminate ' . Framework::version() . "\n",
            $result->stdout,
        );
        self::assertSame('', $result->stderr);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function usageFailureInvocations(): iterable
    {
        yield 'unknown command' => [['missing']];
        yield 'unknown option' => [['--missing']];
        yield 'version extra argument' => [['version', 'unexpected']];
        yield 'long version extra argument' => [['--version', 'unexpected']];
        yield 'short version extra argument' => [['-V', 'unexpected']];
        yield 'long help extra argument' => [['--help', 'unexpected']];
        yield 'short help extra argument' => [['-h', 'unexpected']];
        yield 'help too many arguments' => [['help', 'one', 'two']];
        yield 'unknown help topic' => [['help', 'missing']];
        yield 'case sensitive command' => [['VERSION']];
    }

    /** @param list<string> $arguments */
    #[DataProvider('usageFailureInvocations')]
    public function testUsageErrorsUseStderrAndExit64(array $arguments): void
    {
        $result = new ConsoleApplication()->run($arguments);

        self::assertSame(64, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertStringContainsString(
            'php careminate --help',
            $result->stderr,
        );
        self::assertStringEndsWith("\n", $result->stderr);
    }

    public function testUnknownCommandCannotInjectTerminalControlCharacters(): void
    {
        $result = new ConsoleApplication()->run([
            "missing\n\e[31mPRIVATE\e[0m",
        ]);

        self::assertSame(64, $result->exitCode);
        self::assertStringNotContainsString('PRIVATE', $result->stderr);
        self::assertStringNotContainsString("\e", $result->stderr);
    }

    public function testRegisteredCommandReceivesArgumentsAndReturnsResult(): void
    {
        $received = [];
        $app = new ConsoleApplication();

        $app->register(
            'route:list',
            'List registered HTTP routes',
            static function (array $arguments) use (&$received): ConsoleResult {
                $received = $arguments;

                return new ConsoleResult(0, "routes\n");
            },
        );

        $result = $app->run(['route:list', '--format=json']);

        self::assertSame(['--format=json'], $received);
        self::assertSame(0, $result->exitCode);
        self::assertSame("routes\n", $result->stdout);
        self::assertSame('', $result->stderr);
    }

    public function testRegisteredCommandCanReturnNonzeroStatus(): void
    {
        $app = new ConsoleApplication();

        $app->register(
            'app:check',
            'Check the application',
            static fn (array $arguments): ConsoleResult =>
                new ConsoleResult(
                    78,
                    stderr: "Configuration missing\n",
                ),
        );

        $result = $app->run(['app:check']);

        self::assertSame(78, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertSame("Configuration missing\n", $result->stderr);
    }

    public function testRegisteredHelpIsAlphabeticallySorted(): void
    {
        $app = new ConsoleApplication();

        $handler = static fn (array $arguments): ConsoleResult =>
            new ConsoleResult(0);

        $app->register('zeta', 'Zeta command', $handler);
        $app->register('alpha', 'Alpha command', $handler);

        $help = $app->run(['--help'])->stdout;
        $alpha = strpos($help, 'alpha');
        $zeta = strpos($help, 'zeta');

        self::assertIsInt($alpha);
        self::assertIsInt($zeta);
        self::assertLessThan($zeta, $alpha);
    }

    public function testHelpForRegisteredCommandShowsItsDescription(): void
    {
        $app = new ConsoleApplication();

        $app->register(
            'route:list',
            'List registered HTTP routes',
            static fn (array $arguments): ConsoleResult =>
                new ConsoleResult(0),
        );

        $result = $app->run(['help', 'route:list']);

        self::assertSame(0, $result->exitCode);
        self::assertStringContainsString(
            'php careminate route:list',
            $result->stdout,
        );
        self::assertStringContainsString(
            'List registered HTTP routes',
            $result->stdout,
        );
        self::assertSame('', $result->stderr);
    }

    /** @return iterable<string, array{string, string}> */
    public static function builtInHelpTopics(): iterable
    {
        yield 'help' => ['help', 'php careminate help [command]'];
        yield 'version' => ['version', 'php careminate version'];
    }

    #[DataProvider('builtInHelpTopics')]
    public function testBuiltInHelpTopics(
        string $name,
        string $expected,
    ): void {
        $result = new ConsoleApplication()->run(['help', $name]);

        self::assertSame(0, $result->exitCode);
        self::assertStringContainsString($expected, $result->stdout);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCommandNames(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Route:List'];
        yield 'spaces' => ['route list'];
        yield 'leading dash' => ['--route'];
        yield 'trailing colon' => ['route:'];
        yield 'double colon' => ['route::list'];
        yield 'newline' => ["route\nlist"];
        yield 'oversized' => [str_repeat('a', 65)];
    }

    #[DataProvider('invalidCommandNames')]
    public function testInvalidRegisteredNamesAreRejected(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConsoleApplication()->register(
            $name,
            'Description',
            static fn (array $arguments): ConsoleResult =>
                new ConsoleResult(0),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDescriptions(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['  '];
        yield 'leading whitespace' => [' Description'];
        yield 'trailing whitespace' => ['Description '];
        yield 'terminal escape' => ["Description\e[31m"];
        yield 'newline' => ["Description\nInjected"];
        yield 'oversized' => [str_repeat('x', 121)];
    }

    #[DataProvider('invalidDescriptions')]
    public function testInvalidDescriptionsAreRejected(
        string $description,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new ConsoleApplication()->register(
            'route:list',
            $description,
            static fn (array $arguments): ConsoleResult =>
                new ConsoleResult(0),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function reservedNames(): iterable
    {
        yield 'help' => ['help'];
        yield 'version' => ['version'];
    }

    #[DataProvider('reservedNames')]
    public function testBuiltInCommandNamesCannotBeOverwritten(
        string $name,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new ConsoleApplication()->register(
            $name,
            'Override',
            static fn (array $arguments): ConsoleResult =>
                new ConsoleResult(0),
        );
    }

    public function testDuplicateRegistrationFails(): void
    {
        $app = new ConsoleApplication();

        $handler = static fn (array $arguments): ConsoleResult =>
            new ConsoleResult(0);

        $app->register('route:list', 'First registration', $handler);

        $this->expectException(LogicException::class);

        $app->register('route:list', 'Second registration', $handler);
    }

    public function testRegistrationsDoNotLeakAcrossApplications(): void
    {
        $first = new ConsoleApplication();
        $second = new ConsoleApplication();

        $first->register(
            'route:list',
            'List routes',
            static fn (array $arguments): ConsoleResult =>
                new ConsoleResult(0),
        );

        self::assertSame(0, $first->run(['route:list'])->exitCode);
        self::assertSame(64, $second->run(['route:list'])->exitCode);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidExitCodes(): iterable
    {
        yield 'negative' => [-1];
        yield 'overflow' => [256];
    }

    #[DataProvider('invalidExitCodes')]
    public function testConsoleResultRejectsInvalidExitCodes(
        int $code,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new ConsoleResult($code);
    }

    public function testConsoleResultPreservesSeparateOutputStreams(): void
    {
        $result = new ConsoleResult(
            70,
            stdout: "partial output\n",
            stderr: "error\n",
        );

        self::assertSame(70, $result->exitCode);
        self::assertSame("partial output\n", $result->stdout);
        self::assertSame("error\n", $result->stderr);
    }
}
