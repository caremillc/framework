<?php

declare(strict_types=1);

namespace Careminate\Container\Compilation;

/**
 * @internal
 */
enum DefinitionLifetime: string
{
    case Transient = 'transient';
    case Singleton = 'singleton';
    case Scoped = 'scoped';
}
