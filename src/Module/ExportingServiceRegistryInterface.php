<?php

declare(strict_types=1);

namespace Careminate\Module;

/**
 * Service registration supporting explicit module exports.
 *
 * @api
 */
interface ExportingServiceRegistryInterface extends ServiceRegistryInterface
{
    /**
     * Export a service already registered in this module's registry.
     */
    public function export(string $id): void;
}
