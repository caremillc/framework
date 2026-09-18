<?php

declare(strict_types=1);

namespace Careminate\Container\Compilation;

/**
 * Validates compiled registration before publishing definition artifacts.
 *
 * @internal
 */
final readonly class CompiledArtifactPublisher
{
    public function __construct(
        private FilesystemArtifactStore $store,
    ) {
    }

    public function publish(
        string $key,
        DefinitionSet $definitions,
        string $buildId,
    ): string {
        new CompiledDefinitionContainerFactory()->create($definitions);

        return $this->store->save($key, $definitions, $buildId);
    }
}
