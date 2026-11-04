<?php

declare(strict_types=1);

namespace Careminate\Console;

use InvalidArgumentException;

/**
 * Immutable outcome of a console invocation.
 *
 * Messages already contain their final newline, if appropriate.
 */
final readonly class ConsoleResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout = '',
        public string $stderr = '',
    ) {
        if ($exitCode < 0 || $exitCode > 255) {
            throw new InvalidArgumentException(
                'Console exit code must be between 0 and 255.',
            );
        }
    }
}
