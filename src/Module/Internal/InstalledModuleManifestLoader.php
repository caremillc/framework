<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Module\Exception\ModuleDiscoveryException;
use Closure;
use Composer\InstalledVersions;
use Throwable;

/**
 * Reads bounded manifests for explicitly selected installed packages.
 *
 * @internal
 */
final readonly class InstalledModuleManifestLoader
{
    private const int MAX_MANIFEST_BYTES = 1048576;

    /**
     * @var Closure(string): ?string
     */
    private Closure $installPath;

    /**
     * @param null|Closure(string): ?string $installPath
     */
    public function __construct(?Closure $installPath = null)
    {
        $this->installPath = $installPath
            ?? static fn (string $package): ?string =>
                InstalledVersions::getInstallPath($package);
    }

    /**
     * @param iterable<string> $packages
     *
     * @return list<ComposerModuleEntry>
     */
    public function load(iterable $packages): array
    {
        return new ComposerModuleManifestReader()->read(
            $this->loadManifests($packages),
        );
    }

    /**
     * @param iterable<string> $packages
     *
     * @return list<ComposerModuleEntry>
     */
    public function loadIndexed(iterable $packages): array
    {
        return new ComposerModuleManifestReader()->readIndexed(
            $this->loadManifests($packages),
        );
    }

    /**
     * @param iterable<string> $packages
     *
     * @return array<string, string>
     */
    private function loadManifests(iterable $packages): array
    {
        /** @var array<string, true> $selected */
        $selected = [];

        foreach ($packages as $package) {
            if ($package === '') {
                throw new ModuleDiscoveryException(
                    'A selected package name must not be empty.',
                );
            }

            $selected[$package] = true;
        }

        ksort($selected, SORT_STRING);

        $manifests = [];

        foreach (array_keys($selected) as $package) {
            try {
                $path = ($this->installPath)($package);
            } catch (Throwable $previous) {
                throw new ModuleDiscoveryException(
                    message: 'A selected package install path could not be resolved.',
                    previous: $previous,
                );
            }

            if ($path === null || $path === '' || str_contains($path, "\0")) {
                throw new ModuleDiscoveryException(
                    'A selected package has no usable installation directory.',
                );
            }

            $directory = realpath($path);

            if ($directory === false || !is_dir($directory)) {
                throw new ModuleDiscoveryException(
                    'A selected package installation directory is unavailable.',
                );
            }

            $manifestPath = $directory . DIRECTORY_SEPARATOR . 'composer.json';

            $manifests[$package] = $this->readManifest($manifestPath);
        }

        return $manifests;
    }

    private function readManifest(string $path): string
    {
        clearstatcache(true, $path);

        if (is_link($path) || !is_file($path)) {
            throw new ModuleDiscoveryException(
                'A selected package manifest must be a regular non-symlink file.',
            );
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new ModuleDiscoveryException(
                'A selected package manifest could not be opened.',
            );
        }

        try {
            $stat = fstat($handle);

            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
                throw new ModuleDiscoveryException(
                    'An opened package manifest must be a regular file.',
                );
            }

            $contents = stream_get_contents(
                $handle,
                self::MAX_MANIFEST_BYTES + 1,
            );

            if ($contents === false) {
                throw new ModuleDiscoveryException(
                    'A selected package manifest could not be read.',
                );
            }

            if (strlen($contents) > self::MAX_MANIFEST_BYTES) {
                throw new ModuleDiscoveryException(
                    'A selected package manifest exceeds the size limit.',
                );
            }

            return $contents;
        } finally {
            fclose($handle);
        }
    }
}
