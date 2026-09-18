<?php

declare(strict_types=1);

namespace Careminate\Container\Compilation;

use Careminate\Container\Compilation\Internal\ArtifactEnvelopeCodec;
use Careminate\Container\Compilation\Internal\DefinitionPayloadCodec;

/**
 * @internal
 */
final class DefinitionArtifactCodec
{
    public function encode(
        DefinitionSet $definitions,
        string $buildId,
    ): string {
        $payload = new DefinitionPayloadCodec()->encode($definitions);

        return new ArtifactEnvelopeCodec()->encode($payload, $buildId);
    }

    public function decode(
        string $artifact,
        string $expectedBuildId,
        string $expectedFingerprint,
    ): DefinitionSet {
        $payload = new ArtifactEnvelopeCodec()->decode(
            $artifact,
            $expectedBuildId,
            $expectedFingerprint,
        );

        return new DefinitionPayloadCodec()->decode($payload);
    }

    public function fingerprint(DefinitionSet $definitions): string
    {
        $payload = new DefinitionPayloadCodec()->encode($definitions);

        return new ArtifactEnvelopeCodec()->fingerprint($payload);
    }
}
