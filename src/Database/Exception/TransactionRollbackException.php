<?php

declare(strict_types=1);

namespace Flint\Database\Exception;

use RuntimeException;
use Throwable;

final class TransactionRollbackException extends RuntimeException
{
    public function __construct(
        public readonly Throwable $operationFailure,
        public readonly Throwable $rollbackFailure,
    ) {
        parent::__construct(
            'Database operation and transaction rollback both failed.',
            previous: $operationFailure,
        );
    }
}
