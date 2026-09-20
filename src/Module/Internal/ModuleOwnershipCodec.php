<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Module\Exception\InvalidModuleDefinitionException;
use Careminate\Module\Exception\ModuleCacheException;
use Careminate\Module\ModuleIdentifier;
use JsonException;
use stdClass;

/**
 * Encodes ownership metadata without loading or constructing module classes.
 *
 * @internal
 */
final class ModuleOwnershipCodec
{
    private const int MAX_BYTES = 1048576;

    public function encode(ModuleOwnershipManifest $manifest): string
    {
        $modules = [];

        foreach ($manifest->modules as $module) {
            $modules[] = $module->value;
        }

        $services = [];

        foreach ($manifest->services as $service) {
            $services[] = [
                'id' => base64_encode($service['id']),
                'owner' => $service['owner']->value,
            ];
        }

        try {
            $json = json_encode(
                [
                    'schema' => 1,
                    'modules' => $modules,
                    'services' => $services,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $previous) {
            throw new ModuleCacheException(
                message: 'Module ownership metadata could not be encoded.',
                previous: $previous,
            );
        }

        $this->assertSize($json);

        return $json;
    }

    public function decode(string $json): ModuleOwnershipManifest
    {
        $this->assertSize($json);

        try {
            $document = json_decode(
                $json,
                associative: false,
                depth: 16,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $previous) {
            throw new ModuleCacheException(
                message: 'Module ownership metadata contains invalid JSON.',
                previous: $previous,
            );
        }

        if (!$document instanceof stdClass) {
            throw new ModuleCacheException(
                'Module ownership metadata must be an object.',
            );
        }

        $this->assertFields($document, ['schema', 'modules', 'services']);

        if (
            $document->schema !== 1
            || !is_array($document->modules)
            || !array_is_list($document->modules)
            || !is_array($document->services)
            || !array_is_list($document->services)
        ) {
            throw new ModuleCacheException(
                'Module ownership metadata has an unsupported structure.',
            );
        }

        $modules = [];

        foreach ($document->modules as $name) {
            $modules[] = $this->identifier($name);
        }

        $services = [];

        foreach ($document->services as $entry) {
            if (!$entry instanceof stdClass) {
                throw new ModuleCacheException(
                    'An ownership declaration must be an object.',
                );
            }

            $this->assertFields($entry, ['id', 'owner']);

            if (!is_string($entry->id)) {
                throw new ModuleCacheException(
                    'An ownership declaration requires an encoded service identifier.',
                );
            }

            $id = base64_decode($entry->id, strict: true);

            if ($id === false || base64_encode($id) !== $entry->id) {
                throw new ModuleCacheException(
                    'An owned service identifier has invalid base64 encoding.',
                );
            }

            $services[] = [
                'id' => $id,
                'owner' => $this->identifier($entry->owner),
            ];
        }

        return new ModuleOwnershipManifest($modules, $services);
    }

    private function identifier(mixed $value): ModuleIdentifier
    {
        if (!is_string($value)) {
            throw new ModuleCacheException(
                'An ownership module identifier must be a string.',
            );
        }

        try {
            return new ModuleIdentifier($value);
        } catch (InvalidModuleDefinitionException $previous) {
            throw new ModuleCacheException(
                message: 'An ownership module identifier is invalid.',
                previous: $previous,
            );
        }
    }

    /**
     * @param list<string> $expected
     */
    private function assertFields(stdClass $object, array $expected): void
    {
        $actual = array_keys(get_object_vars($object));

        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        if ($actual !== $expected) {
            throw new ModuleCacheException(
                'Module ownership metadata has unexpected fields.',
            );
        }
    }

    private function assertSize(string $json): void
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new ModuleCacheException(
                'Module ownership metadata exceeds the size limit.',
            );
        }
    }
}
