<?php

declare(strict_types=1);

namespace Flint\Configuration;

use Flint\Configuration\Exception\ConfigurationException;
use Throwable;

final class PhpConfigurationLoader
{
    public function load(string $path): Configuration
    {
        if (
            $path === ''
            || str_contains($path, "\0")
            || !is_file($path)
            || !is_readable($path)
        ) {
            throw new ConfigurationException(
                'The configuration file must be an existing readable file.',
            );
        }

        try {
            $values = self::execute($path);
        } catch (Throwable) {
            throw new ConfigurationException(
                'The configuration file could not be evaluated.',
            );
        }

        if (!is_array($values)) {
            throw new ConfigurationException(
                'The configuration file must return an array.',
            );
        }

        return new Configuration($values);
    }

    private static function execute(string $path): mixed
    {
        return require $path;
    }
}
