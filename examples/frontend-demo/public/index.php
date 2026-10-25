<?php

declare(strict_types=1);

use Careminate\Http\Emission\NativeResponseOutput;
use Careminate\Http\Emission\ResponseEmitter;
use Careminate\Http\Runtime\HttpRunner;
use Careminate\Http\ServerRequestFactory;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$bootstrap = require dirname(__DIR__) . '/bootstrap.php';

$kernel = $bootstrap();

$runner = new HttpRunner(
    new ServerRequestFactory(),
    $kernel,
    new ResponseEmitter(
        new NativeResponseOutput(),
    ),
);

try {
    $runner->runFromGlobals($_SERVER);
} finally {
    $kernel->terminate();
}
