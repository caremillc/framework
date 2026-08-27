<?php

declare(strict_types=1);

namespace Careminate\Exception\Application;

use Throwable;

final class OptimizationException extends ApplicationException
{
    public static function failed(
        string $path,
        Throwable $previous,
    ): self {
        return new self(
            sprintf(
                'Application optimization failed for cache "%s": %s',
                $path,
                $previous->getMessage(),
            ),
            0,
            $previous,
        );
    }

    public static function clearFailed(string $path): self
    {
        return new self(
            sprintf(
                'Unable to remove application optimization cache "%s".',
                $path,
            ),
        );
    }
}
