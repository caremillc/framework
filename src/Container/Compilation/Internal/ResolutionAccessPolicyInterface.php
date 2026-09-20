<?php

declare(strict_types=1);

namespace Careminate\Container\Internal;

/**
 * Evaluates access between canonical registered service identifiers.
 *
 * The container must supply trusted requester context.
 *
 * @internal
 */
interface ResolutionAccessPolicyInterface
{
    /**
     * A null requester represents an application-level lookup.
     *
     * Identifiers must be canonical service identifiers after alias
     * resolution, without the container's internal key prefixes.
     */
    public function allows(
        ?string $requesterService,
        string $targetService,
    ): bool;
}
