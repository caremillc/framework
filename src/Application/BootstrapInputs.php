<?php

declare(strict_types=1);

namespace Careminate\Application;

use Careminate\Application\Exception\InvalidApplicationInputException;

/**
 * Explicit application-wide inputs supplied by the composition root.
 *
 * @api
 */
final readonly class BootstrapInputs
{
    public function __construct(
        public ApplicationPaths $paths,
        public string $environment = 'production',
        public bool $debug = false,
    ) {
        if (
            preg_match(
                '/\A[a-z][a-z0-9_-]{0,63}\z/',
                $environment,
            ) !== 1
        ) {
            throw new InvalidApplicationInputException(
                'The environment name must contain 1 to 64 lowercase letters, digits, underscores or hyphens and start with a letter.',
            );
        }
    }
}
