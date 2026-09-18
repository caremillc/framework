<?php

declare(strict_types=1);

namespace Careminate\Container\Compilation;

/**
 * A snapshot produced by DefinitionBuilder.
 *
 * @internal
 */
final readonly class DefinitionSet
{
    /**
     * @param list<ServiceDefinition> $services
     * @param list<array{alias: string, target: string}> $aliases
     * @param list<array{name: string, identifiers: list<string>}> $tags
     * @param list<array{
     *     consumer: string,
     *     dependency: string,
     *     target: string
     * }> $contexts
     */
    public function __construct(
        public array $services,
        public array $aliases,
        public array $tags,
        public array $contexts,
    ) {
    }
}
