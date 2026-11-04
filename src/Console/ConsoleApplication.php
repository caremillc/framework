<?php

declare(strict_types=1);

namespace Careminate\Console;

use Careminate\Foundation\Framework;
use Closure;
use InvalidArgumentException;
use LogicException;

/**
 * Deterministic CLI command dispatcher, independent of HTTP bootstrapping.
 *
 * Commands receive only the arguments following their command name.
 * Output and exit status are returned, not written to global streams.
 */
final class ConsoleApplication
{
    public const int EXIT_SUCCESS = 0;
    public const int EXIT_USAGE = 64;

    /**
     * @var array<string, array{
     *     description: string,
     *     handler: Closure(list<string>): ConsoleResult
     * }>
     */
    private array $commands = [];

    /**
     * Register an application-defined command for later dispatch.
     *
     * @param Closure(list<string>): ConsoleResult $handler
     */
    public function register(
        string $name,
        string $description,
        Closure $handler,
    ): void {
        if (
            strlen($name) > 64
            || preg_match(
                '/\A[a-z][a-z0-9]*(?::[a-z][a-z0-9]*)*\z/D',
                $name,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Invalid console command name.',
            );
        }

        if ($name === 'help' || $name === 'version') {
            throw new InvalidArgumentException(
                'Built-in console commands cannot be replaced.',
            );
        }

        if (
            $description === ''
            || trim($description) !== $description
            || strlen($description) > 120
            || preg_match('/[\x00-\x1F\x7F]/', $description) !== 0
        ) {
            throw new InvalidArgumentException(
                'Invalid console command description.',
            );
        }

        if (isset($this->commands[$name])) {
            throw new LogicException('Console command already registered.');
        }

        $this->commands[$name] = [
            'description' => $description,
            'handler' => $handler,
        ];
    }

    /**
     * @param list<string> $arguments Command arguments, excluding executable.
     */
    public function run(array $arguments): ConsoleResult
    {
        $name = $arguments[0] ?? 'help';

        if ($name === 'help') {
            if (count($arguments) > 2) {
                return $this->usageError('Too many help arguments.');
            }

            return isset($arguments[1])
                ? $this->commandHelp($arguments[1])
                : $this->generalHelp();
        }

        if ($name === '-h' || $name === '--help') {
            return count($arguments) === 1
                ? $this->generalHelp()
                : $this->usageError('The help option accepts no arguments.');
        }

        if (
            $name === 'version'
            || $name === '-V'
            || $name === '--version'
        ) {
            return count($arguments) === 1
                ? new ConsoleResult(
                    self::EXIT_SUCCESS,
                    'Careminate ' . Framework::version() . "\n",
                )
                : $this->usageError(
                    'The version command accepts no arguments.',
                );
        }

        if (! isset($this->commands[$name])) {
            // Do not echo untrusted arguments into a terminal.
            return $this->usageError('Unknown console command.');
        }

        return ($this->commands[$name]['handler'])(
            array_slice($arguments, 1),
        );
    }

    private function generalHelp(): ConsoleResult
    {
        $lines = [
            'Careminate ' . Framework::version(),
            '',
            'Usage:',
            '  php careminate [command] [arguments]',
            '',
            'Options:',
            '  -h, --help       Show this help',
            '  -V, --version    Show framework version',
            '',
            'Commands:',
            '  help             Show CLI help',
            '  version          Show framework version',
        ];

        $commands = $this->commands;
        ksort($commands, SORT_STRING);

        foreach ($commands as $name => $command) {
            $lines[] = sprintf(
                '  %-16s %s',
                $name,
                $command['description'],
            );
        }

        return new ConsoleResult(
            self::EXIT_SUCCESS,
            implode("\n", $lines) . "\n",
        );
    }

    private function commandHelp(string $name): ConsoleResult
    {
        if ($name === 'help') {
            return new ConsoleResult(
                self::EXIT_SUCCESS,
                "Usage:\n  php careminate help [command]\n",
            );
        }

        if ($name === 'version') {
            return new ConsoleResult(
                self::EXIT_SUCCESS,
                "Usage:\n  php careminate version\n",
            );
        }

        if (! isset($this->commands[$name])) {
            return $this->usageError('Unknown help topic.');
        }

        return new ConsoleResult(
            self::EXIT_SUCCESS,
            sprintf(
                "Usage:\n  php careminate %s [arguments]\n\n%s\n",
                $name,
                $this->commands[$name]['description'],
            ),
        );
    }

    private function usageError(string $message): ConsoleResult
    {
        return new ConsoleResult(
            self::EXIT_USAGE,
            '',
            $message . "\nRun \"php careminate --help\" for usage.\n",
        );
    }
}
