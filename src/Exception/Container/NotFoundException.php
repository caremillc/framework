<?php

declare(strict_types=1);

namespace Careminate\Exception\Container;

use Psr\Container\NotFoundExceptionInterface;

final class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
    /**
     * @param list<string> $resolutionPath
     */
    public static function forIdentifier(
        string $id,
        array $resolutionPath = [],
    ): self {
        $path = [...$resolutionPath, $id];

        return new self(
            sprintf(
                'Container entry "%s" was not found. Resolution path: %s.',
                $id,
                implode(' -> ', $path),
            ),
            $path,
        );
    }
}
