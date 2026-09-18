<?php

declare(strict_types=1);

namespace Careminate\Container\Compilation\Internal;

use Careminate\Container\Compilation\DefinitionBuilder;
use Careminate\Container\Compilation\DefinitionLifetime;
use Careminate\Container\Compilation\DefinitionSet;
use Careminate\Container\Compilation\Exception\ArtifactException;
use Careminate\Container\Compilation\Exception\DefinitionException;
use Careminate\Container\Compilation\Exception\PortableValueException;
use Careminate\Container\Compilation\ServiceDefinition;

/**
 * @internal
 */
final class DefinitionPayloadCodec
{
    private const int VERSION = 1;

    public function encode(DefinitionSet $definitions): string
    {
        // Use the same validation path for captured and decoded definitions.
        $validated = $this->decode($this->encodeRecords($definitions));

        return $this->encodeRecords($validated);
    }

    public function decode(string $payload): DefinitionSet
    {
        try {
            $document = $this->tuple(
                new PortableValueCodec()->decode($payload),
                5,
            );

            if ($document[0] !== self::VERSION) {
                throw new ArtifactException(
                    'The definition payload version is incompatible.',
                );
            }

            $builder = new DefinitionBuilder();

            foreach ($this->items($document[1]) as $record) {
                $builder->add($this->decodeService($record));
            }

            foreach ($this->items($document[2]) as $record) {
                $alias = $this->tuple($record, 2);

                $builder->alias(
                    $this->text($alias[0]),
                    $this->text($alias[1]),
                );
            }

            foreach ($this->items($document[3]) as $record) {
                $tag = $this->tuple($record, 2);
                $identifiers = [];

                foreach ($this->items($tag[1]) as $identifier) {
                    $identifiers[] = $this->text($identifier);
                }

                $builder->tag($this->text($tag[0]), ...$identifiers);
            }

            foreach ($this->items($document[4]) as $record) {
                $context = $this->tuple($record, 3);

                $builder->bindContext(
                    $this->text($context[0]),
                    $this->text($context[1]),
                    $this->text($context[2]),
                );
            }

            return $builder->build();
        } catch (PortableValueException | DefinitionException $previous) {
            throw new ArtifactException(
                message: 'The definition payload is invalid.',
                previous: $previous,
            );
        }
    }

    private function encodeRecords(DefinitionSet $definitions): string
    {
        $services = [];

        foreach ($definitions->services as $definition) {
            if ($definition->isValue()) {
                $services[] = [
                    'value',
                    $definition->id,
                    $definition->value(),
                ];

                continue;
            }

            $services[] = [
                'autowire',
                $definition->id,
                $definition->className,
                $definition->lifetime?->value,
                $definition->lazy,
                $definition->arguments(),
                $definition->taggedArguments(),
            ];
        }

        $aliases = [];

        foreach ($definitions->aliases as $alias) {
            $aliases[] = [$alias['alias'], $alias['target']];
        }

        $tags = [];

        foreach ($definitions->tags as $tag) {
            $tags[] = [$tag['name'], $tag['identifiers']];
        }

        $contexts = [];

        foreach ($definitions->contexts as $context) {
            $contexts[] = [
                $context['consumer'],
                $context['dependency'],
                $context['target'],
            ];
        }

        try {
            return new PortableValueCodec()->encode([
                self::VERSION,
                $services,
                $aliases,
                $tags,
                $contexts,
            ]);
        } catch (PortableValueException $previous) {
            throw new ArtifactException(
                message: 'The definition payload could not be encoded.',
                previous: $previous,
            );
        }
    }

    private function decodeService(mixed $record): ServiceDefinition
    {
        $record = $this->items($record);
        $kind = $record[0] ?? null;

        if ($kind === 'value') {
            $record = $this->tuple($record, 3);

            return ServiceDefinition::forValue(
                $this->text($record[1]),
                $record[2],
            );
        }

        if ($kind !== 'autowire') {
            throw new ArtifactException(
                'The definition payload contains an unknown service kind.',
            );
        }

        $record = $this->tuple($record, 7);
        $lifetime = DefinitionLifetime::tryFrom($this->text($record[3]));

        if ($lifetime === null) {
            throw new ArtifactException(
                'The definition payload contains an unknown lifetime.',
            );
        }

        if (!is_bool($record[4])) {
            throw new ArtifactException(
                'The lazy definition flag must be a boolean.',
            );
        }

        return ServiceDefinition::forAutowire(
            id: $this->text($record[1]),
            className: $this->text($record[2]),
            lifetime: $lifetime,
            arguments: $this->argumentMap($record[5]),
            taggedArguments: $this->argumentMap($record[6]),
            lazy: $record[4],
        );
    }

    /**
     * @return list<mixed>
     */
    private function items(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new ArtifactException(
                'A definition record or collection must be a list.',
            );
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    private function tuple(mixed $value, int $length): array
    {
        $items = $this->items($value);

        if (count($items) !== $length) {
            throw new ArtifactException(
                'A definition record has an invalid field count.',
            );
        }

        return $items;
    }

    private function text(mixed $value): string
    {
        if (!is_string($value)) {
            throw new ArtifactException(
                'A definition identifier or name must be a string.',
            );
        }

        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function argumentMap(mixed $value): array
    {
        if (!is_array($value)) {
            throw new ArtifactException(
                'Definition arguments must be an array.',
            );
        }

        return $value;
    }
}
