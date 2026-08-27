<?php

declare(strict_types=1);

namespace Careminate\Module;

enum ModuleStatus: string
{
    case Discovered = 'discovered';
    case Disabled = 'disabled';
    case Planned = 'planned';
    case Registered = 'registered';
    case Booted = 'booted';
    case Failed = 'failed';
}