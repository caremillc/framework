<?php

declare(strict_types=1);

namespace Flint\Database\Exception;

use RuntimeException;
use Throwable;

final class MigrationExecutionException extends RuntimeException
{
    public function __construct(
        public readonly Throwable $operationFailure,
        public readonly Throwable $releaseFailure,
    ) {
        parent::__construct(
            'Migration execution and lock release both failed.',
            previous: $operationFailure,
        );
    }
}
