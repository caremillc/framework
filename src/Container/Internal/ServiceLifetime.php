<?php

declare(strict_types=1);

namespace Careminate\Container\Internal;

/**
 * Internal lifetime classification for factory definitions.
 *
 * @internal
 */
enum ServiceLifetime
{
    case Transient;
    case Singleton;
    case Scoped;
}
