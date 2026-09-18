<?php

declare(strict_types=1);

namespace Careminate\Application;

use Psr\Container\ContainerInterface;

/**
 * Executes application work after successful bootstrap.
 *
 * @api
 */
interface RuntimeInterface
{
    public function run(ContainerInterface $container): int;
}
