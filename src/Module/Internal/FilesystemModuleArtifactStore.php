<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Module\Exception\ModuleCacheException;
use Throwable;

/**
 * Publishes and loads bounded module artifacts in a trusted directory.
 *
 * @internal
 */
final readonly class FilesystemModuleArtifactStore
{
    private const int MAX_BYTES = 8388608;

    private string $directory;

    public function __construct(string $directory)
    {
        if ($directory === '' || str_contains($directory, "\0")) {
            throw new ModuleCacheException(
                'The module artifact directory is invalid.',
            );
        }

        $resolved = realpath($directory);

        if ($resolved === false || !is_dir($resolved)) {
            throw new ModuleCacheException(
                'The module artifact directory must already exist.',
            );
        }

        $this->directory = $resolved;
    }

    public function save(
        string $key,
        ModuleServiceDefinitions $snapshot,
        ModuleCacheIdentity $identity,
    ): string {
        $destination = $this->path($key);
        $codec = new ModuleArtifactCodec();

        // Finish serialization and validation before touching the destination.
        $artifact = $codec->encode($snapshot, $identity);
        $fingerprint = $codec->fingerprint($artifact);
        $codec->decode($artifact, $identity, $fingerprint);

        $this->assertWritableDestination($destination);

        $temporary = @tempnam($this->directory, 'cmm-');

        if ($temporary === false) {
            throw new ModuleCacheException(
                'A temporary module artifact could not be created.',
            );
        }

        try {
            if (realpath(dirname($temporary)) !== $this->directory) {
                throw new ModuleCacheException(
                    'The temporary module artifact is outside its directory.',
                );
            }

            $written = @file_put_contents($temporary, $artifact, LOCK_EX);

            if ($written !== strlen($artifact)) {
                throw new ModuleCacheException(
                    'The temporary module artifact was not written completely.',
                );
            }

            $this->assertWritableDestination($destination);

            if (!@rename($temporary, $destination)) {
                throw new ModuleCacheException(
                    'The module artifact could not be published.',
                );
            }
        } catch (Throwable $previous) {
            clearstatcache(true, $temporary);

            if (
                (is_file($temporary) || is_link($temporary))
                && !@unlink($temporary)
            ) {
                throw new ModuleCacheException(
                    message: 'Module artifact publication failed and temporary '
                        . 'cleanup failed.',
                    previous: $previous,
                );
            }

            throw $previous;
        }

        clearstatcache(true, $destination);

        return $fingerprint;
    }

    public function load(
        string $key,
        ModuleCacheIdentity $expectedIdentity,
        string $expectedFingerprint,
    ): ModuleServiceDefinitions {
        $path = $this->path($key);

        clearstatcache(true, $path);

        if (is_link($path) || !is_file($path)) {
            throw new ModuleCacheException(
                'The module artifact must be a regular non-symlink file.',
            );
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new ModuleCacheException(
                'The module artifact could not be opened.',
            );
        }

        try {
            $stat = fstat($handle);

            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
                throw new ModuleCacheException(
                    'The opened module artifact must be a regular file.',
                );
            }

            $artifact = @stream_get_contents(
                $handle,
                self::MAX_BYTES + 1,
            );

            if ($artifact === false) {
                throw new ModuleCacheException(
                    'The module artifact could not be read.',
                );
            }

            if (strlen($artifact) > self::MAX_BYTES) {
                throw new ModuleCacheException(
                    'The module artifact exceeds the size limit.',
                );
            }
        } finally {
            fclose($handle);
        }

        return new ModuleArtifactCodec()->decode(
            $artifact,
            $expectedIdentity,
            $expectedFingerprint,
        );
    }

    private function path(string $key): string
    {
        if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $key) !== 1) {
            throw new ModuleCacheException(
                'A module artifact key must contain 1 to 64 lowercase ASCII '
                . 'letters, digits, underscores or hyphens and start '
                . 'with a letter or digit.',
            );
        }

        return $this->directory
            . DIRECTORY_SEPARATOR
            . $key
            . '.module.json';
    }

    private function assertWritableDestination(string $path): void
    {
        clearstatcache(true, $path);

        if (
            is_link($path)
            || (file_exists($path) && !is_file($path))
        ) {
            throw new ModuleCacheException(
                'A module artifact destination must be absent or a regular '
                . 'non-symlink file.',
            );
        }
    }
}
