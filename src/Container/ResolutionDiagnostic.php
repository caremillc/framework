<?php

declare(strict_types=1);

namespace Careminate\Container;

final readonly class ResolutionDiagnostic
{
    /**
     * @param list<string> $aliases
     * @param list<string> $tags
     */
    public function __construct(
        public string $requestedId,
        public string $resolvedId,
        public bool $registered,
        public bool $resolvable,
        public ?string $target,
        public ?Lifetime $lifetime,
        public bool $lazy,
        public array $aliases,
        public array $tags,
        public ?string $error,
    ) {
    }

    /**
     * @return array<string, bool|string|array<int, string>|null>
     */
    public function toArray(): array
    {
        return [
            'requested_id' => $this->requestedId,
            'resolved_id' => $this->resolvedId,
            'registered' => $this->registered,
            'resolvable' => $this->resolvable,
            'target' => $this->target,
            'lifetime' => $this->lifetime?->value,
            'lazy' => $this->lazy,
            'aliases' => $this->aliases,
            'tags' => $this->tags,
            'error' => $this->error,
        ];
    }
}
