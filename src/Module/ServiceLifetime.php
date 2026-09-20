<?php

declare(strict_types=1);

namespace Careminate\Module;

/**
 * Lifetime of a service contributed by a module.
 *
 * @api
 */
enum ServiceLifetime: string
{
    case Transient = 'transient';
    case Singleton = 'singleton';
    case Scoped = 'scoped';
}
