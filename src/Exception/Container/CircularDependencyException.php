<?php

declare(strict_types=1);

namespace Careminate\Exception\Container;

final class CircularDependencyException extends ContainerException
{
    /**
     * @param list<string> $path
     */
    public static function forPath(array $path): self
    {
        return new self(
            sprintf(
                'Circular container dependency detected: %s.',
                implode(' -> ', $path),
            ),
            $path,
        );
    }
}