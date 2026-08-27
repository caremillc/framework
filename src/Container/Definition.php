<?php

declare(strict_types=1);

namespace Careminate\Container;

use Careminate\Contracts\Container\FactoryInterface;
use Careminate\Exception\Container\ContainerCompilationException;
use Careminate\Exception\Container\InvalidDefinitionException;
use Closure;
use ValueError;

final readonly class Definition
{
    private function __construct(
        private string $id,
        private DefinitionKind $kind,
        private mixed $resolver,
        private Lifetime $lifetime,
        private bool $lazy,
    ) {
    }

    public static function forClass(
        string $id,
        string $className,
        Lifetime $lifetime,
        bool $lazy,
    ): self {
        if ($className === '') {
            throw InvalidDefinitionException::invalidConcrete($id);
        }

        return new self(
            $id,
            DefinitionKind::ClassName,
            $className,
            $lifetime,
            $lazy,
        );
    }

    public static function forCallableFactory(
        string $id,
        callable $factory,
        Lifetime $lifetime,
    ): self {
        return new self(
            $id,
            DefinitionKind::CallableFactory,
            Closure::fromCallable($factory),
            $lifetime,
            false,
        );
    }

    public static function forFactoryService(
        string $id,
        string $factoryService,
        Lifetime $lifetime,
    ): self {
        return new self(
            $id,
            DefinitionKind::FactoryService,
            $factoryService,
            $lifetime,
            false,
        );
    }

    public static function forFactoryObject(
        string $id,
        FactoryInterface $factory,
        Lifetime $lifetime,
    ): self {
        return new self(
            $id,
            DefinitionKind::FactoryObject,
            $factory,
            $lifetime,
            false,
        );
    }

    public static function forValue(string $id, mixed $value): self
    {
        return new self(
            $id,
            DefinitionKind::Value,
            $value,
            Lifetime::Singleton,
            false,
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function kind(): DefinitionKind
    {
        return $this->kind;
    }

    public function resolver(): mixed
    {
        return $this->resolver;
    }

    public function lifetime(): Lifetime
    {
        return $this->lifetime;
    }

    public function lazy(): bool
    {
        return $this->lazy;
    }

    public function targetDescription(): string
    {
        return is_string($this->resolver)
            ? $this->resolver
            : $this->kind->value;
    }

    /**
     * @return array{
     *     id: string,
     *     kind: string,
     *     resolver: mixed,
     *     lifetime: string,
     *     lazy: bool
     * }
     */
    public function toSnapshot(): array
    {
        if (
            $this->kind === DefinitionKind::CallableFactory
            || $this->kind === DefinitionKind::FactoryObject
        ) {
            throw ContainerCompilationException::unsupportedDefinition(
                $this->id,
                $this->kind->value,
            );
        }

        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'resolver' => $this->resolver,
            'lifetime' => $this->lifetime->value,
            'lazy' => $this->lazy,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromSnapshot(array $data): self
    {
        $id = $data['id'] ?? null;
        $kind = $data['kind'] ?? null;
        $lifetime = $data['lifetime'] ?? null;
        $lazy = $data['lazy'] ?? null;

        if (
            !is_string($id)
            || !is_string($kind)
            || !is_string($lifetime)
            || !is_bool($lazy)
            || !array_key_exists('resolver', $data)
        ) {
            throw ContainerCompilationException::invalidSnapshot(
                'A definition has missing or invalid fields.',
            );
        }

        try {
            $definitionKind = DefinitionKind::from($kind);
            $definitionLifetime = Lifetime::from($lifetime);
        } catch (ValueError $exception) {
            throw ContainerCompilationException::invalidSnapshot(
                $exception->getMessage(),
            );
        }

        if (
            $definitionKind === DefinitionKind::CallableFactory
            || $definitionKind === DefinitionKind::FactoryObject
        ) {
            throw ContainerCompilationException::invalidSnapshot(
                sprintf('Definition "%s" contains a runtime-only resolver.', $id),
            );
        }

        return new self(
            $id,
            $definitionKind,
            $data['resolver'],
            $definitionLifetime,
            $lazy,
        );
    }
}
