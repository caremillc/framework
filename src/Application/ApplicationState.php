<?php

declare(strict_types=1);

namespace Careminate\Application;

enum ApplicationState: string
{
    case Created = 'created';
    case Bootstrapping = 'bootstrapping';
    case Bootstrapped = 'bootstrapped';
    case Running = 'running';
    case Failed = 'failed';
    case Terminating = 'terminating';
    case Terminated = 'terminated';
}