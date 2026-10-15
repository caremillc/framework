<?php

declare(strict_types=1);

namespace Flint\Foundation;

use RuntimeException;
use Throwable;

final class ApplicationRunException extends RuntimeException
{
    public function __construct(
        public readonly Throwable $executionFailure,
        public readonly Throwable $terminationFailure,
    ) {
        parent::__construct(
            'Application execution and termination both failed.',
            previous: $executionFailure,
        );
    }
}
