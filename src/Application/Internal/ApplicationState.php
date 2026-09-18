<?php

declare(strict_types=1);

namespace Careminate\Application\Internal;

/**
 * Application-wide lifecycle state.
 *
 * @internal
 */
enum ApplicationState: string
{
    case Created = 'created';
    case Booting = 'booting';
    case Booted = 'booted';
    case Terminating = 'terminating';
    case Terminated = 'terminated';
    case Failed = 'failed';
}
