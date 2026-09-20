<?php

declare(strict_types=1);

namespace Careminate\Module;

/**
 * Entry point for an explicitly discoverable module.
 *
 * @api
 */
interface ModuleInterface extends ServiceProviderInterface
{
    public static function definition(): ModuleDefinition;
}
