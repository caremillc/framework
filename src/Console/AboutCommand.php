<?php

declare(strict_types=1);

namespace Careminate\Console;

use Careminate\Foundation\Framework;
use JsonException;

/**
 * Read-only, deliberately limited framework and PHP runtime diagnostics.
 *
 * Never reads environment variables, configuration, credentials, or paths.
 */
final class AboutCommand
{
    /**
     * @param list<string> $arguments
     */
    public function __invoke(array $arguments): ConsoleResult
    {
        if ($arguments === [] || $arguments === ['--format=table']) {
            return $this->table();
        }

        if ($arguments === ['--format=json']) {
            return $this->json();
        }

        return new ConsoleResult(
            ConsoleApplication::EXIT_USAGE,
            stderr: "Invalid about arguments.\n"
                . "Usage: php careminate about [--format=table|json]\n",
        );
    }

    private function table(): ConsoleResult
    {
        $snapshot = $this->snapshot();
        $runtime = $snapshot['runtime'];
        $extensions = $snapshot['extensions'];

        $rows = [
            ['Framework', $snapshot['framework']['name']],
            ['Framework version', $snapshot['framework']['version']],
            ['Minimum PHP', $runtime['minimum_php_version']],
            ['PHP version', $runtime['php_version']],
            ['Runtime supported', self::status($runtime['supported'])],
            ['PHP SAPI', $runtime['sapi']],
            ['OS family', $runtime['os_family']],
            ['Architecture', $runtime['architecture_bits'] . '-bit'],
            ['Extension json', self::status($extensions['json'])],
            ['Extension mbstring', self::status($extensions['mbstring'])],
            ['Extension pdo', self::status($extensions['pdo'])],
        ];

        $lines = ['Careminate runtime information', ''];

        foreach ($rows as [$label, $value]) {
            $lines[] = sprintf('  %-21s %s', $label, $value);
        }

        return new ConsoleResult(
            0,
            implode("\n", $lines) . "\n",
        );
    }

    private function json(): ConsoleResult
    {
        try {
            $json = json_encode(
                $this->snapshot(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException) {
            return new ConsoleResult(
                70,
                stderr: "Careminate: Cannot encode runtime information.\n",
            );
        }

        return new ConsoleResult(0, $json . "\n");
    }

    private static function status(bool $enabled): string
    {
        return $enabled ? 'yes' : 'no';
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
    private function snapshot(): array
    {
        return [
            'framework' => [
                'name' => 'Careminate',
                'version' => Framework::version(),
            ],
            'runtime' => [
                'php_version' => PHP_VERSION,
                'minimum_php_version' => Framework::MINIMUM_PHP_VERSION,
                'supported' => Framework::supportsRuntime(),
                'sapi' => PHP_SAPI,
                'os_family' => PHP_OS_FAMILY,
                'architecture_bits' => PHP_INT_SIZE * 8,
            ],
            'extensions' => [
                'json' => extension_loaded('json'),
                'mbstring' => extension_loaded('mbstring'),
                'pdo' => extension_loaded('pdo'),
            ],
        ];
    }
}
