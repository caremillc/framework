<?php

declare(strict_types=1);

namespace Careminate\Exception\Container;

final class ScopeException extends ContainerException
{
    public static function required(string $id): self
    {
        return new self(
            sprintf(
                'Container entry "%s" is scoped and must be resolved through a scoped container.',
                $id,
            ),
            [$id],
        );
    }

    public static function closed(string $scope): self
    {
        return new self(
            sprintf('Container scope "%s" is already closed.', $scope),
        );
    }
}
