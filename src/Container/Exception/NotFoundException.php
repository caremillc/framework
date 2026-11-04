<?php

declare(strict_types=1);

namespace Careminate\Container\Exception;

use Psr\Container\NotFoundExceptionInterface;

final class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
    public function __construct(string $id)
    {
        parent::__construct(
            sprintf('Service "%s" is not registered.', $id)
        );
    }
}
