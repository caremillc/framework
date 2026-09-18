<?php

declare(strict_types=1);

namespace Careminate\Application;

use Psr\Container\ContainerInterface;

/**
 * Releases resources acquired by a successfully completed bootstrapper.
 *
 * @api
 */
interface TerminableBootstrapperInterface extends BootstrapperInterface
{
    public function terminate(ContainerInterface $container): void;
}
