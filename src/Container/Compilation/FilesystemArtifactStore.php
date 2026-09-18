<?php

declare(strict_types=1);

namespace Careminate\Container\Compilation;

use Careminate\Container\Compilation\Exception\ArtifactException;
use Throwable;

/**
 * @internal
 */
final class FilesystemArtifactStore
{
    private const int MAX_ARTIFACT_BYTES = 1048576;

    private readonly string $directory;

    public function __construct(string $directory)
    {
        if ($directory === '' || str_contains($directory, "\0")) {
            throw new ArtifactException(
                'The artifact directory is invalid.',
            );
        }

        $resolved = realpath($directory);

        if ($resolved === false || !is_dir($resolved)) {
            throw new ArtifactException(
                'The artifact directory must already exist.',
            );
        }

        $this->directory = $resolved;
    }

    public function save(
        string $key,
        DefinitionSet $definitions,
        string $buildId,
    ): string {
        $destination = $this->path($key);
        $codec = new DefinitionArtifactCodec();

        // Complete encoding before touching the filesystem.
        $artifact = $codec->encode($definitions, $buildId);
        $fingerprint = $codec->fingerprint($definitions);

        clearstatcache(true, $destination);

        if (is_link($destination)) {
            throw new ArtifactException(
                'An artifact destination must not be a symbolic link.',
            );
        }

        $temporary = @tempnam($this->directory, 'cma-');

        if ($temporary === false) {
            throw new ArtifactException(
                'The artifact temporary file could not be created.',
            );
        }

        try {
            // tempnam may fall back to the system temporary directory.
            if (realpath(dirname($temporary)) !== $this->directory) {
                throw new ArtifactException(
                    'The artifact temporary file was created outside its directory.',
                );
            }

            $written = @file_put_contents(
                $temporary,
                $artifact,
                LOCK_EX,
            );

            if ($written !== strlen($artifact)) {
                throw new ArtifactException(
                    'The artifact temporary file was not written completely.',
                );
            }

            clearstatcache(true, $destination);

            if (is_link($destination)) {
                throw new ArtifactException(
                    'An artifact destination must not be a symbolic link.',
                );
            }

            if (!@rename($temporary, $destination)) {
                throw new ArtifactException(
                    'The artifact could not be published.',
                );
            }
        } catch (Throwable $previous) {
            clearstatcache(true, $temporary);

            if (file_exists($temporary) && !@unlink($temporary)) {
                throw new ArtifactException(
                    message: 'Artifact publication failed and temporary cleanup failed.',
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
        string $expectedBuildId,
        string $expectedFingerprint,
    ): DefinitionSet {
        $path = $this->path($key);

        clearstatcache(true, $path);

        if (is_link($path) || !is_file($path)) {
            throw new ArtifactException(
                'The artifact must be an existing regular file.',
            );
        }

        $artifact = @file_get_contents(
            $path,
            false,
            null,
            0,
            self::MAX_ARTIFACT_BYTES + 1,
        );

        if ($artifact === false) {
            throw new ArtifactException(
                'The artifact could not be read.',
            );
        }

        if (strlen($artifact) > self::MAX_ARTIFACT_BYTES) {
            throw new ArtifactException(
                'The artifact exceeds its file size limit.',
            );
        }

        return new DefinitionArtifactCodec()->decode(
            $artifact,
            $expectedBuildId,
            $expectedFingerprint,
        );
    }

    private function path(string $key): string
    {
        if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $key) !== 1) {
            throw new ArtifactException(
                'An artifact key must contain 1 to 64 lowercase letters, digits, underscores or hyphens and start with a letter or digit.',
            );
        }

        return $this->directory
            . DIRECTORY_SEPARATOR
            . 'container-'
            . $key
            . '.json';
    }
}
