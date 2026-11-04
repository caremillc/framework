<?php

declare(strict_types=1);

namespace Careminate\Http\Exception;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        if ($status < 400 || $status > 599) {
            throw new InvalidArgumentException(
                'HTTP exception status must be between 400 and 599.'
            );
        }

        parent::__construct(
            $message,
            $status,
            $previous,
        );
    }
}
