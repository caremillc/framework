<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Exception\FrameworkException;
use Careminate\Module\Exception\ModuleDiscoveryException;
use Careminate\Module\ModuleIdentifier;
use JsonException;
use stdClass;

/**
 * Reads module declarations from explicitly selected package manifests.
 *
 * @internal
 */
final class ComposerModuleManifestReader
{
    private const int MAX_MANIFEST_BYTES = 1048576;

    /**
     * Reads the legacy list of entry-point class names.
     *
     * @param array<string, string> $manifests Package name to manifest JSON.
     *
     * @return list<ComposerModuleEntry>
     */
    public function read(array $manifests): array
    {
        return $this->readDeclarations($manifests, false);
    }

    /**
     * Reads declarations containing explicit module identifiers.
     *
     * No entry-point classes are autoloaded.
     *
     * @param array<string, string> $manifests Package name to manifest JSON.
     *
     * @return list<ComposerModuleEntry>
     */
    public function readIndexed(array $manifests): array
    {
        return $this->readDeclarations($manifests, true);
    }

    /**
     * @param array<string, string> $manifests
     *
     * @return list<ComposerModuleEntry>
     */
    private function readDeclarations(
        array $manifests,
        bool $indexed,
    ): array {
        ksort($manifests, SORT_STRING);

        $entries = [];

        /** @var array<string, true> $seenClasses */
        $seenClasses = [];

        /** @var array<string, true> $seenModules */
        $seenModules = [];

        foreach ($manifests as $package => $json) {
            $manifest = $this->decode($json);

            if (
                !isset($manifest->name)
                || !is_string($manifest->name)
                || $manifest->name !== $package
                || $package === ''
            ) {
                throw new ModuleDiscoveryException(
                    'A module manifest does not match its selected package.',
                );
            }

            if (!property_exists($manifest, 'extra')) {
                continue;
            }

            $extra = $manifest->extra;

            if (!$extra instanceof stdClass) {
                throw new ModuleDiscoveryException(
                    'Selected package extra metadata must be an object.',
                );
            }

            if (!property_exists($extra, 'careminate')) {
                continue;
            }

            $metadata = $extra->careminate;

            if (!$metadata instanceof stdClass) {
                throw new ModuleDiscoveryException(
                    'Careminate package metadata must be an object.',
                );
            }

            if (
                !property_exists($metadata, 'modules')
                || !is_array($metadata->modules)
                || !array_is_list($metadata->modules)
            ) {
                throw new ModuleDiscoveryException(
                    'Careminate modules must be a JSON list.',
                );
            }

            foreach ($metadata->modules as $declaration) {
                $entry = $indexed
                    ? $this->indexedEntry($package, $declaration)
                    : $this->legacyEntry($package, $declaration);

                $classKey = strtolower($entry->className);

                if (isset($seenClasses[$classKey])) {
                    throw new ModuleDiscoveryException(
                        'A module entry-point class is declared more than once.',
                    );
                }

                $seenClasses[$classKey] = true;

                if ($entry->moduleId !== null) {
                    $name = $entry->moduleId->value;

                    if (isset($seenModules[$name])) {
                        throw new ModuleDiscoveryException(
                            'An indexed module identifier is declared more than once.',
                        );
                    }

                    $seenModules[$name] = true;
                }

                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function legacyEntry(
        string $package,
        mixed $declaration,
    ): ComposerModuleEntry {
        if (
            !is_string($declaration)
            || !$this->isClassName($declaration)
        ) {
            throw new ModuleDiscoveryException(
                'A module declaration must contain a valid class name.',
            );
        }

        return new ComposerModuleEntry($package, $declaration);
    }

    private function indexedEntry(
        string $package,
        mixed $declaration,
    ): ComposerModuleEntry {
        if (!$declaration instanceof stdClass) {
            throw new ModuleDiscoveryException(
                'An indexed module declaration must be an object.',
            );
        }

        $fields = array_keys(get_object_vars($declaration));
        sort($fields, SORT_STRING);

        if ($fields !== ['class', 'id']) {
            throw new ModuleDiscoveryException(
                'An indexed module declaration has unexpected fields.',
            );
        }

        if (
            !is_string($declaration->id)
            || !is_string($declaration->class)
            || !$this->isClassName($declaration->class)
        ) {
            throw new ModuleDiscoveryException(
                'An indexed module declaration has invalid field values.',
            );
        }

        try {
            $identifier = new ModuleIdentifier($declaration->id);
        } catch (FrameworkException $previous) {
            throw new ModuleDiscoveryException(
                message: 'An indexed module identifier is invalid.',
                previous: $previous,
            );
        }

        return new ComposerModuleEntry(
            $package,
            $declaration->class,
            $identifier,
        );
    }

    private function decode(string $json): stdClass
    {
        if (strlen($json) > self::MAX_MANIFEST_BYTES) {
            throw new ModuleDiscoveryException(
                'A selected package manifest exceeds the size limit.',
            );
        }

        try {
            $manifest = json_decode(
                $json,
                associative: false,
                depth: 32,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $previous) {
            throw new ModuleDiscoveryException(
                message: 'A selected package manifest contains invalid JSON.',
                previous: $previous,
            );
        }

        if (!$manifest instanceof stdClass) {
            throw new ModuleDiscoveryException(
                'A selected package manifest must be a JSON object.',
            );
        }

        return $manifest;
    }

    private function isClassName(string $className): bool
    {
        foreach (explode('\\', $className) as $segment) {
            if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $segment) !== 1) {
                return false;
            }
        }

        return true;
    }
}
