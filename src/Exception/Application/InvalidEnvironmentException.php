<?php

declare(strict_types=1);

namespace Careminate\Exception\Application;

final class InvalidEnvironmentException extends ApplicationException
{
    public static function invalidName(string $name): self
    {
        return new self(
            sprintf(
                'Application environment name "%s" is invalid. Use lowercase letters, numbers, underscores, or hyphens.',
                $name,
            ),
        );
    }

    public static function productionDebugging(): self
    {
        return new self(
            'Debug mode cannot be enabled for the production environment.',
        );
    }
}
