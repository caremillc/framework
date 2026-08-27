<?php

declare(strict_types=1);

namespace Careminate\Module;

enum ModuleManagerState: string
{
    case New = 'new';
    case Discovering = 'discovering';
    case Planned = 'planned';
    case Registering = 'registering';
    case Booting = 'booting';
    case Booted = 'booted';
    case Failed = 'failed';
}