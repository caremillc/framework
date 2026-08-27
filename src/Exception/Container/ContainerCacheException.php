<?php

declare(strict_types=1);

namespace Careminate\Exception\Container;

use Throwable;

final class ContainerCacheException extends ContainerException
{
    public static function directoryUnavailable(string $directory): self
    {
        return new self(
            sprintf(
                'Container cache directory "%s" does not exist or is not writable.',
                $directory,
            ),
        );
    }

    public static function writeFailed(string $path): self
    {
        return new self(
            sprintf('Unable to write container cache "%s".', $path),
        );
    }

    public static function readFailed(
        string $path,
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf('Unable to load container cache "%s".', $path),
            [],
            $previous,
        );
    }
}
