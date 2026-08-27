<?php

declare(strict_types=1);

namespace Careminate\Exception\Container;

use Throwable;

final class UnresolvableDependencyException extends ContainerException
{
    /**
     * @param list<string> $resolutionPath
     */
    public static function forParameter(
        string $consumer,
        string $parameter,
        array $resolutionPath,
    ): self {
        return new self(
            sprintf(
                'Cannot resolve parameter "$%s" while constructing "%s". Resolution path: %s.',
                $parameter,
                $consumer,
                implode(' -> ', $resolutionPath),
            ),
            $resolutionPath,
        );
    }

    /**
     * @param list<string> $resolutionPath
     */
    public static function ambiguousUnion(
        string $consumer,
        string $parameter,
        array $resolutionPath,
    ): self {
        return new self(
            sprintf(
                'Parameter "$%s" on "%s" has multiple resolvable union members. Add an Inject attribute or contextual binding.',
                $parameter,
                $consumer,
            ),
            $resolutionPath,
        );
    }

    /**
     * @param list<string> $resolutionPath
     */
    public static function whileResolving(
        string $id,
        array $resolutionPath,
        Throwable $previous,
    ): self {
        return new self(
            sprintf(
                'Container entry "%s" failed during resolution: %s',
                $id,
                $previous->getMessage(),
            ),
            $resolutionPath,
            $previous,
        );
    }
}
