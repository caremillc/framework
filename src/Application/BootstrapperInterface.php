<?php

declare(strict_types=1);

namespace Careminate\Application;

use Psr\Container\ContainerInterface;

/**
 * Performs one ordered application bootstrap operation.
 *
 * @api
 */
interface BootstrapperInterface
{
    public function bootstrap(ContainerInterface $container): void;
}
