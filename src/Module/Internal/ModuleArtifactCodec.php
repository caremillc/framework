<?php

declare(strict_types=1);

namespace Careminate\Module\Internal;

use Careminate\Container\Compilation\DefinitionArtifactCodec;
use Careminate\Container\Compilation\DefinitionBuilder;
use Careminate\Container\Compilation\DefinitionSet;
use Careminate\Exception\FrameworkException;
use Careminate\Module\Exception\ModuleCacheException;
use JsonException;
use stdClass;

/**
 * Binds module metadata, services, ownership, exports, and cache identity.
 *
 * @internal
 */
final class ModuleArtifactCodec
{
    private const int MAX_BYTES = 8388608;

    public function encode(
        ModuleServiceDefinitions $snapshot,
        ModuleCacheIdentity $identity,
    ): string {
        $metadata = new ModuleMetadataCodec()->encode($snapshot->modules);
        $ownership = ModuleOwnershipManifest::fromDefinitions($snapshot);

        $validated = $this->assemble(
            $metadata,
            $snapshot->definitions,
            $ownership,
            $snapshot->exportedServices,
        );

        $definitions = new DefinitionArtifactCodec();
        $identityHash = $identity->fingerprint();

        $document = [
            'schema' => $validated->exportedServices === [] ? 1 : 2,
            'identity' => $identityHash,
            'metadata' => $metadata,
            'ownership' => new ModuleOwnershipCodec()->encode($ownership),
            'definitions' => $definitions->encode(
                $validated->definitions,
                $identityHash,
            ),
            'definitionFingerprint' => $definitions->fingerprint(
                $validated->definitions,
            ),
        ];

        if ($validated->exportedServices !== []) {
            $exports = [];

            foreach ($validated->exportedServices as $id) {
                $exports[] = base64_encode($id);
            }

            $document['exports'] = $exports;
        }

        try {
            $artifact = json_encode(
                $document,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $previous) {
            throw new ModuleCacheException(
                message: 'The module artifact could not be encoded.',
                previous: $previous,
            );
        }

        $this->assertSize($artifact);

        return $artifact;
    }

    public function fingerprint(string $artifact): string
    {
        $this->assertSize($artifact);

        return hash('sha256', $artifact);
    }

    public function decode(
        string $artifact,
        ModuleCacheIdentity $expectedIdentity,
        string $expectedFingerprint,
    ): ModuleServiceDefinitions {
        $this->assertSize($artifact);

        if (
            preg_match('/\A[a-f0-9]{64}\z/', $expectedFingerprint) !== 1
            || !hash_equals(
                $expectedFingerprint,
                $this->fingerprint($artifact),
            )
        ) {
            throw new ModuleCacheException(
                'The module artifact fingerprint does not match.',
            );
        }

        try {
            $document = json_decode(
                $artifact,
                associative: false,
                depth: 8,
                flags: JSON_THROW_ON_ERROR,
            );

            if (!$document instanceof stdClass) {
                throw new ModuleCacheException(
                    'The module artifact must be an object.',
                );
            }

            $schema = $document->schema ?? null;

            if ($schema !== 1 && $schema !== 2) {
                throw new ModuleCacheException(
                    'The module artifact has an unsupported structure.',
                );
            }

            $expectedFields = [
                'definitionFingerprint',
                'definitions',
                'identity',
                'metadata',
                'ownership',
                'schema',
            ];

            if ($schema === 2) {
                $expectedFields[] = 'exports';
            }

            $fields = array_keys(get_object_vars($document));
            sort($fields, SORT_STRING);
            sort($expectedFields, SORT_STRING);

            if ($fields !== $expectedFields) {
                throw new ModuleCacheException(
                    'The module artifact has unexpected fields.',
                );
            }

            if (
                !is_string($document->identity)
                || !is_string($document->metadata)
                || !is_string($document->ownership)
                || !is_string($document->definitions)
                || !is_string($document->definitionFingerprint)
            ) {
                throw new ModuleCacheException(
                    'The module artifact has an unsupported structure.',
                );
            }

            if (!hash_equals(
                $expectedIdentity->fingerprint(),
                $document->identity,
            )) {
                throw new ModuleCacheException(
                    'The module artifact cache identity does not match.',
                );
            }

            $exports = $schema === 2
                ? $this->decodeExports($document->exports)
                : [];

            $definitions = new DefinitionArtifactCodec()->decode(
                $document->definitions,
                $expectedIdentity->fingerprint(),
                $document->definitionFingerprint,
            );

            $ownership = new ModuleOwnershipCodec()->decode(
                $document->ownership,
            );

            return $this->assemble(
                $document->metadata,
                $definitions,
                $ownership,
                $exports,
            );
        } catch (ModuleCacheException $exception) {
            throw $exception;
        } catch (JsonException | FrameworkException $previous) {
            throw new ModuleCacheException(
                message: 'The module artifact contains invalid data.',
                previous: $previous,
            );
        }
    }

    /**
     * @return list<string>
     */
    private function decodeExports(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new ModuleCacheException(
                'Module artifact exports must be a JSON list.',
            );
        }

        $exports = [];
        $seen = [];

        foreach ($value as $encoded) {
            if (!is_string($encoded)) {
                throw new ModuleCacheException(
                    'A module artifact export must be a Base64 string.',
                );
            }

            $id = base64_decode($encoded, strict: true);

            if ($id === false || base64_encode($id) !== $encoded) {
                throw new ModuleCacheException(
                    'A module artifact export has invalid Base64 encoding.',
                );
            }

            $key = 'entry:' . $id;

            if (isset($seen[$key])) {
                throw new ModuleCacheException(
                    'A module artifact export is duplicated.',
                );
            }

            $seen[$key] = true;
            $exports[] = $id;
        }

        return $exports;
    }

    /**
     * @param list<string> $exports
     */
    private function assemble(
        string $metadata,
        DefinitionSet $definitions,
        ModuleOwnershipManifest $ownership,
        array $exports = [],
    ): ModuleServiceDefinitions {
        $modules = new ModuleMetadataCodec()->decode($metadata);

        if (
            $definitions->aliases !== []
            || $definitions->tags !== []
            || $definitions->contexts !== []
        ) {
            throw new ModuleCacheException(
                'This module artifact version supports owned services only.',
            );
        }

        $moduleNames = [];

        foreach ($modules as $module) {
            $moduleNames[] = $module->id->value;
        }

        sort($moduleNames, SORT_STRING);

        $ownershipNames = [];

        foreach ($ownership->modules as $module) {
            $ownershipNames[] = $module->value;
        }

        if ($moduleNames !== $ownershipNames) {
            throw new ModuleCacheException(
                'Artifact metadata and ownership name different modules.',
            );
        }

        if (count($definitions->services) !== count($ownership->services)) {
            throw new ModuleCacheException(
                'Artifact services and ownership declarations do not match.',
            );
        }

        $builder = new DefinitionBuilder();
        $owners = [];

        foreach ($definitions->services as $service) {
            $owner = $ownership->ownerOf($service->id);

            if ($owner === null) {
                throw new ModuleCacheException(
                    'A cached service has no matching ownership declaration.',
                );
            }

            $builder->add($service);
            $owners['entry:' . $service->id] = $owner;
        }

        return new ModuleServiceDefinitions(
            $builder->build(),
            $modules,
            $owners,
            $exports,
        );
    }

    private function assertSize(string $artifact): void
    {
        if (strlen($artifact) > self::MAX_BYTES) {
            throw new ModuleCacheException(
                'The module artifact exceeds the size limit.',
            );
        }
    }
}
