<?php

declare(strict_types=1);

namespace Careminate\Exception\Application;

use Careminate\Application\Runtime\RuntimeType;

final class KernelNotFoundException extends ApplicationException
{
    public static function forRuntime(RuntimeType $runtime): self
    {
        return new self(
            sprintf(
                'No application kernel is registered for runtime "%s".',
                $runtime->value,
            ),
        );
    }

    public static function invalidKernel(
        RuntimeType $runtime,
        string $kernel,
    ): self {
        return new self(
            sprintf(
                'Kernel "%s" registered for runtime "%s" must implement the kernel contract.',
                $kernel,
                $runtime->value,
            ),
        );
    }
}
