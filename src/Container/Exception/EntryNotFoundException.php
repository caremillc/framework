<?php

declare(strict_types=1);

namespace Flint\Container\Exception;

use Psr\Container\NotFoundExceptionInterface;

final class EntryNotFoundException extends ContainerException implements NotFoundExceptionInterface
{
}
