<?php

declare(strict_types=1);

namespace Careminate\Exception\Application;

use Throwable;

final class BootstrapException extends ApplicationException
{
    public static function forBootstrapper(
        string $bootstrapper,
        Throwable $previous,
    ): self {
        return new self(
            sprintf(
                'Application bootstrapper "%s" failed: %s',
                $bootstrapper,
                $previous->getMessage(),
            ),
            0,
            $previous,
        );
    }
}
