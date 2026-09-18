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

    public function load(
        string $key,
        string $expectedBuildId,
        string $expectedFingerprint,
    ): Container {
        $definitions = $this->store->load(
            $key,
            $expectedBuildId,
            $expectedFingerprint,
        );

        return new CompiledDefinitionContainerFactory()->create($definitions);
    }
}
