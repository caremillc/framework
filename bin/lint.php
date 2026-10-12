<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$files = [
    $root . '/.php-cs-fixer.dist.php',
];

foreach (['src', 'tests', 'bin'] as $directory) {
    $path = $root . '/' . $directory;

    if (!is_dir($path)) {
        fwrite(STDERR, 'Missing lint directory: ' . $path . PHP_EOL);

        exit(1);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $path,
            FilesystemIterator::SKIP_DOTS,
        ),
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo) {
            continue;
        }

        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $files[] = $file->getPathname();
    }
}

sort($files, SORT_STRING);

foreach ($files as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, 'Missing lint file: ' . $file . PHP_EOL);

        exit(1);
    }

    $process = proc_open(
        [PHP_BINARY, '-l', $file],
        [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ],
        $pipes,
        $root,
        null,
        ['bypass_shell' => true],
    );

    if ($process === false) {
        fwrite(STDERR, 'Could not start PHP syntax checking.' . PHP_EOL);

        exit(1);
    }

    if (proc_close($process) !== 0) {
        exit(1);
    }
}

fwrite(
    STDOUT,
    sprintf('PASS: Syntax checked %d PHP files.%s', count($files), PHP_EOL),
);
