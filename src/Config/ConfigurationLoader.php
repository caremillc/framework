<?php

declare(strict_types=1);

namespace Careminate\Config;

use InvalidArgumentException;
use RuntimeException;

final class ConfigurationLoader
{
    public function load(string $directory, Environment $environment): Configuration
    {
        if (!is_dir($directory) || !is_readable($directory)) {
            throw new RuntimeException(
                'Configuration directory does not exist or is not readable.'
            );
        }

        $files = glob(
            rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.php'
        );

        if ($files === false) {
            throw new RuntimeException(
                'Unable to enumerate configuration files.'
            );
        }

        sort($files, SORT_STRING);

        $items = [];

        foreach ($files as $file) {
            if (!is_file($file) || !is_readable($file)) {
                throw new RuntimeException(
                    'Configuration file is not readable.'
                );
            }

            $name = basename($file, '.php');

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
                throw new InvalidArgumentException(
                    'Invalid configuration namespace.'
                );
            }

            if (array_key_exists($name, $items)) {
                throw new InvalidArgumentException(
                    'Duplicate configuration namespace.'
                );
            }

            $result = (static function (
                string $path,
                Environment $environment
            ): mixed {
                return require $path;
            })($file, $environment);

            if (!is_array($result)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Configuration file "%s" must return an array.',
                        $name
                    )
                );
            }

            foreach (array_keys($result) as $key) {
                if (!is_string($key) || $key === '') {
                    throw new InvalidArgumentException(
                        sprintf(
                            'Configuration namespace "%s" must use non-empty string keys.',
                            $name
                        )
                    );
                }
            }

            /** @var array<string, mixed> $result */
            $items[$name] = $result;
        }

        return new Configuration($items);
    }
}
