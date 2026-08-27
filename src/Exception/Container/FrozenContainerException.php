<?php

declare(strict_types=1);

namespace Careminate\Exception\Container;

final class FrozenContainerException extends ContainerException
{
    public static function compiledContainer(): self
    {
        return new self(
            'A compiled container is frozen and cannot be modified.',
        );
    }
}