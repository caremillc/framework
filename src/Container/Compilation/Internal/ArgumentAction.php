<?php

declare(strict_types=1);

namespace Careminate\Container\Compilation\Internal;

/**
 * @internal
 */
enum ArgumentAction
{
    case Literal;
    case Service;
    case Tagged;
    case OptionalService;
    case OmitDefault;
}
