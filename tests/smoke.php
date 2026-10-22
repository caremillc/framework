<?php

declare(strict_types=1);

use Careminate\Foundation\Framework;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(
        STDERR,
        "Composer autoload file missing. Run composer install.\n"
    );

    exit(1);
}

require $autoload;

if (!class_exists(Framework::class)) {
    fwrite(STDERR, "Framework class cannot be autoloaded.\n");
    exit(1);
}

if (!Framework::supportsRuntime()) {
    fwrite(STDERR, "Unsupported PHP runtime.\n");
    exit(1);
}

if (Framework::version() !== '0.1.0-dev') {
    fwrite(STDERR, "Unexpected framework version.\n");
    exit(1);
}

echo sprintf(
    "Careminate %s running on PHP %s\n",
    Framework::version(),
    PHP_VERSION
);

echo "Smoke test passed.\n";
