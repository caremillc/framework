<?php

declare(strict_types=1);

namespace Careminate\Application\Runtime;

enum RuntimeType: string
{
    case Http = 'http';
    case Console = 'console';
    case Worker = 'worker';
    case Serverless = 'serverless';
    case Desktop = 'desktop';
    case Test = 'test';
}