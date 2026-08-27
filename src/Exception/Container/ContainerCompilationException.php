<?php

declare(strict_types=1);

namespace Careminate\Exception\Container;

final class ContainerCompilationException extends ContainerException
{
    public static function unsupportedDefinition(
        string $id,
        string $kind,
    ): self {
        return new self(
            sprintf(
                'Container entry "%s" uses non-compilable definition kind "%s". Use a class name, factory service, or exportable value.',
                $id,
                $kind,
            ),
        );
    }

    public static function nonExportableValue(string $location): self
    {
        return new self(
            sprintf(
                'Container compilation cannot export the value at "%s". Objects, resources, and closures must not be written to the container cache.',
                $location,
            ),
        );
    }

    public static function invalidSnapshot(string $reason): self
    {
        return new self(
            sprintf('The compiled container snapshot is invalid: %s', $reason),
        );
    }
}
