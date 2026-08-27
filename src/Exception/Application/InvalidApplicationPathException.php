<?php

declare(strict_types=1);

namespace Careminate\Exception\Application;

final class InvalidApplicationPathException extends ApplicationException
{
    public static function baseMustBeAbsolute(string $path): self
    {
        return new self(
            sprintf(
                'Application base path "%s" must be absolute.',
                $path,
            ),
        );
    }

    public static function unknownPath(string $name): self
    {
        return new self(
            sprintf('Unknown application path "%s".', $name),
        );
    }
}
