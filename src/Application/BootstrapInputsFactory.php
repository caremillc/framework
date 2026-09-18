<?php

declare(strict_types=1);

namespace Careminate\Application;

use Careminate\Application\Exception\InvalidApplicationInputException;

/**
 * Parses bootstrap settings from an explicitly supplied environment snapshot.
 *
 * @api
 */
final class BootstrapInputsFactory
{
    /**
     * Only APP_ENV and APP_DEBUG are interpreted.
     *
     * @param array<array-key, mixed> $environment
     */
    public function fromEnvironment(
        ApplicationPaths $paths,
        array $environment,
    ): BootstrapInputs {
        $name = 'production';

        if (array_key_exists('APP_ENV', $environment)) {
            $value = $environment['APP_ENV'];

            if (!is_string($value)) {
                throw new InvalidApplicationInputException(
                    'APP_ENV must be a string.',
                );
            }

            $name = $value;
        }

        $debug = false;

        if (array_key_exists('APP_DEBUG', $environment)) {
            $debug = match ($environment['APP_DEBUG']) {
                '1', 'true' => true,
                '0', 'false' => false,
                default => throw new InvalidApplicationInputException(
                    'APP_DEBUG must be "1", "0", "true" or "false".',
                ),
            };
        }

        return new BootstrapInputs(
            $paths,
            environment: $name,
            debug: $debug,
        );
    }
}
