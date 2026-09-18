<?php

declare(strict_types=1);

namespace Careminate\Container\Compilation;

use Careminate\Container\Container;

/**
 * Loads validated definition artifacts into fresh compiled containers.
 *
 * @internal
 */
final readonly class CompiledArtifactContainerLoader
{
    public function __construct(
        private FilesystemArtifactStore $store,
    ) {
    }

    /**
     * @param array<string, object> $runtimeObjects
     */
    public function load(
        string $key,
        string $expectedBuildId,
        string $expectedFingerprint,
        array $runtimeObjects = [],
    ): Container {
        $definitions = $this->store->load(
            $key,
            $expectedBuildId,
            $expectedFingerprint,
        );

        return new CompiledDefinitionContainerFactory()->create(
            $definitions,
            $runtimeObjects,
        );
    }
}
