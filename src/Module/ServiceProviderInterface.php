<?php

declare(strict_types=1);

namespace Careminate\Module;

/**
 * Declares services without resolving them or booting the application.
 *
 * @api
 */
interface ServiceProviderInterface
{
    public function register(ServiceRegistryInterface $services): void;
}
