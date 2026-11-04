<?php

declare(strict_types=1);

namespace Careminate\Database\Migrations;

/** Discovers trusted, immutable application migration files. */
final readonly class MigrationCatalog
{
    public function __construct(private string $directory)
    {
    }

    /**
     * @return array<string, array{path: string, checksum: string}>
     */
    public function discover(): array
    {
        $directory = realpath($this->directory);

        if ($directory === false || ! is_dir($directory)) {
            throw new MigrationException(
                'Migration directory does not exist.',
            );
        }

        $paths = glob(
            $directory . DIRECTORY_SEPARATOR . '*.php',
        );

        if ($paths === false) {
            throw new MigrationException(
                'Cannot read migration directory.',
            );
        }

        $files = [];

        foreach ($paths as $path) {
            $filename = basename($path);
            $name = substr($filename, 0, -4);
            $realPath = realpath($path);

            if (
                strlen($name) > 160
                || preg_match(
                    '/\A[0-9]{8}_[0-9]{6}_[a-z][a-z0-9_]*\z/D',
                    $name,
                ) !== 1
                || is_link($path)
                || $realPath === false
                || dirname($realPath) !== $directory
                || ! is_file($realPath)
            ) {
                throw new MigrationException(
                    'Invalid migration file.',
                );
            }

            $checksum = hash_file(
                'sha256',
                $realPath,
            );

            if ($checksum === false) {
                throw new MigrationException(
                    'Cannot checksum migration file.',
                );
            }

            if (isset($files[$name])) {
                throw new MigrationException(
                    'Duplicate migration name.',
                );
            }

            $files[$name] = [
                'path' => $realPath,
                'checksum' => $checksum,
            ];
        }

        ksort($files, SORT_STRING);

        return $files;
    }
}
