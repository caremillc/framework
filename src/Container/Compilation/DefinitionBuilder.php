<?php

declare(strict_types=1);

namespace Careminate\Container\Compilation;

use Careminate\Container\Compilation\Exception\DefinitionException;

/**
 * @internal
 */
final class DefinitionBuilder
{
    /**
     * @var array<string, ServiceDefinition>
     */
    private array $services = [];

    /**
     * @var array<string, array{alias: string, target: string}>
     */
    private array $aliases = [];

    /**
     * @var array<string, array{
     *     name: string,
     *     identifiers: list<string>
     * }>
     */
    private array $tags = [];

    /**
     * @var list<array{
     *     consumer: string,
     *     dependency: string,
     *     target: string
     * }>
     */
    private array $contexts = [];

    public function add(ServiceDefinition $definition): void
    {
        $this->assertAvailable($definition->id);

        $this->services['entry:' . $definition->id] = $definition;
    }

    public function alias(string $alias, string $target): void
    {
        $this->assertAvailable($alias);
        $this->assertNonEmpty($target);

        $this->aliases['entry:' . $alias] = [
            'alias' => $alias,
            'target' => $target,
        ];
    }

    public function tag(string $tag, string ...$identifiers): void
    {
        $this->assertNonEmpty($tag);

        foreach ($identifiers as $identifier) {
            $this->assertNonEmpty($identifier);
        }

        if ($identifiers === []) {
            return;
        }

        $key = 'tag:' . $tag;
        $entry = $this->tags[$key] ?? [
            'name' => $tag,
            'identifiers' => [],
        ];

        foreach ($identifiers as $identifier) {
            if (!in_array($identifier, $entry['identifiers'], true)) {
                $entry['identifiers'][] = $identifier;
            }
        }

        $this->tags[$key] = $entry;
    }

    public function bindContext(
        string $consumer,
        string $dependency,
        string $target,
    ): void {
        $this->assertNonEmpty($consumer);
        $this->assertNonEmpty($dependency);
        $this->assertNonEmpty($target);

        $this->contexts[] = [
            'consumer' => $consumer,
            'dependency' => $dependency,
            'target' => $target,
        ];
    }

    public function build(): DefinitionSet
    {
        $targets = $this->resolveAliases();
        $aliases = [];

        foreach ($this->aliases as $key => $entry) {
            $aliases[] = [
                'alias' => $entry['alias'],
                'target' => $targets[$key],
            ];
        }

        foreach ($this->tags as $entry) {
            foreach ($entry['identifiers'] as $identifier) {
                $this->canonicalIdentifier($identifier, $targets);
            }
        }

        $contexts = [];

        /** @var array<string, array<string, true>> $bound */
        $bound = [];

        foreach ($this->contexts as $entry) {
            $consumer = $this->canonicalIdentifier(
                $entry['consumer'],
                $targets,
            );

            $target = $this->canonicalIdentifier(
                $entry['target'],
                $targets,
            );

            $consumerKey = 'entry:' . $consumer;
            $dependencyKey = 'dependency:' . $entry['dependency'];
            $definition = $this->services[$consumerKey];

            if ($definition->isValue()) {
                throw new DefinitionException(
                    'A contextual consumer must be an autowired definition.',
                );
            }

            if (isset($bound[$consumerKey][$dependencyKey])) {
                throw new DefinitionException(
                    'A contextual dependency is bound more than once.',
                );
            }

            $bound[$consumerKey][$dependencyKey] = true;

            $contexts[] = [
                'consumer' => $consumer,
                'dependency' => $entry['dependency'],
                'target' => $target,
            ];
        }

        return new DefinitionSet(
            services: array_values($this->services),
            aliases: $aliases,
            tags: array_values($this->tags),
            contexts: $contexts,
        );
    }

    /**
     * @return array<string, string>
     */
    private function resolveAliases(): array
    {
        $resolved = [];

        foreach ($this->aliases as $startKey => $entry) {
            if (isset($resolved[$startKey])) {
                continue;
            }

            $current = $entry['alias'];
            $path = [];

            /** @var array<string, true> $seen */
            $seen = [];

            while (true) {
                $key = 'entry:' . $current;

                if (isset($this->services[$key])) {
                    $target = $current;

                    break;
                }

                if (isset($resolved[$key])) {
                    $target = $resolved[$key];

                    break;
                }

                if (isset($seen[$key])) {
                    throw new DefinitionException(
                        'The definitions contain a circular alias chain.',
                    );
                }

                $alias = $this->aliases[$key] ?? null;

                if ($alias === null) {
                    throw new DefinitionException(
                        'An alias target has no service definition.',
                    );
                }

                $seen[$key] = true;
                $path[] = $key;
                $current = $alias['target'];
            }

            foreach ($path as $key) {
                $resolved[$key] = $target;
            }
        }

        return $resolved;
    }

    /**
     * @param array<string, string> $targets
     */
    private function canonicalIdentifier(
        string $identifier,
        array $targets,
    ): string {
        $key = 'entry:' . $identifier;

        if (isset($this->services[$key])) {
            return $identifier;
        }

        if (isset($targets[$key])) {
            return $targets[$key];
        }

        throw new DefinitionException(
            'A referenced registration has no service definition.',
        );
    }

    private function assertAvailable(string $identifier): void
    {
        $this->assertNonEmpty($identifier);

        $key = 'entry:' . $identifier;

        if (isset($this->services[$key]) || isset($this->aliases[$key])) {
            throw new DefinitionException(
                'A registration identifier is already defined.',
            );
        }
    }

    private function assertNonEmpty(string $value): void
    {
        if ($value === '') {
            throw new DefinitionException(
                'Definition identifiers and names must not be empty.',
            );
        }
    }
}
