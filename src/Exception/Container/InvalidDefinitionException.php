<?php

declare(strict_types=1);

namespace Careminate\Exception\Container;

final class InvalidDefinitionException extends ContainerException
{
    public static function emptyIdentifier(): self
    {
        return new self('A container identifier must not be empty.');
    }

    public static function duplicate(string $id): self
    {
        return new self(
            sprintf(
                'Container entry "%s" is already registered. Use replace() for an intentional override.',
                $id,
            ),
        );
    }

    public static function invalidConcrete(string $id): self
    {
        return new self(
            sprintf(
                'Container entry "%s" must use a class name or callable factory.',
                $id,
            ),
        );
    }

    public static function invalidAlias(string $alias, string $id): self
    {
        return new self(
            sprintf(
                'Container alias "%s" cannot target itself as "%s".',
                $alias,
                $id,
            ),
        );
    }

    public static function reserved(string $id): self
    {
        return new self(
            sprintf('Container identifier "%s" is reserved.', $id),
        );
    }
}
