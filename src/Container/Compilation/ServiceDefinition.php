<?php

declare(strict_types=1);

namespace Careminate\Container\Compilation;

use Careminate\Container\Compilation\Exception\DefinitionException;
use Careminate\Container\Compilation\Internal\PortableValueCodec;
use LogicException;

/**
 * @internal
 */
final readonly class ServiceDefinition
{
    private function __construct(
        public string $id,
        public ?string $className,
        public ?DefinitionLifetime $lifetime,
        public bool $lazy,
        private string $encodedValue,
        private string $encodedArguments,
        private string $encodedTaggedArguments,
    ) {
    }

    public static function forValue(string $id, mixed $value): self
    {
        self::validateIdentifier($id);

        $codec = new PortableValueCodec();

        return new self(
            id: $id,
            className: null,
            lifetime: null,
            lazy: false,
            encodedValue: $codec->encode($value),
            encodedArguments: $codec->encode([]),
            encodedTaggedArguments: $codec->encode([]),
        );
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param array<array-key, mixed> $taggedArguments
     */
    public static function forAutowire(
        string $id,
        string $className,
        DefinitionLifetime $lifetime = DefinitionLifetime::Transient,
        array $arguments = [],
        array $taggedArguments = [],
        bool $lazy = false,
    ): self {
        self::validateIdentifier($id);

        if ($className === '') {
            throw new DefinitionException(
                'An autowired definition requires a non-empty class name.',
            );
        }

        if ($lazy && $lifetime !== DefinitionLifetime::Singleton) {
            throw new DefinitionException(
                'A lazy definition requires the singleton lifetime.',
            );
        }

        foreach ($arguments as $name => $value) {
            self::validateParameterName($name);
        }

        foreach ($taggedArguments as $name => $tag) {
            self::validateParameterName($name);

            if (!is_string($tag) || $tag === '') {
                throw new DefinitionException(
                    'A tagged argument requires a non-empty tag name.',
                );
            }

            if (array_key_exists($name, $arguments)) {
                throw new DefinitionException(
                    'A parameter cannot have both literal and tagged arguments.',
                );
            }
        }

        $codec = new PortableValueCodec();

        return new self(
            id: $id,
            className: $className,
            lifetime: $lifetime,
            lazy: $lazy,
            encodedValue: $codec->encode(null),
            encodedArguments: $codec->encode($arguments),
            encodedTaggedArguments: $codec->encode($taggedArguments),
        );
    }

    public function isValue(): bool
    {
        return $this->className === null;
    }

    public function value(): mixed
    {
        if (!$this->isValue()) {
            throw new LogicException(
                'An autowired definition does not contain a literal value.',
            );
        }

        return new PortableValueCodec()->decode($this->encodedValue);
    }

    /**
     * @return array<string, mixed>
     */
    public function arguments(): array
    {
        $decoded = new PortableValueCodec()->decode($this->encodedArguments);

        if (!is_array($decoded)) {
            throw new LogicException(
                'The stored constructor arguments are invalid.',
            );
        }

        $arguments = [];

        foreach ($decoded as $name => $value) {
            if (!is_string($name)) {
                throw new LogicException(
                    'A stored constructor argument name is invalid.',
                );
            }

            $arguments[$name] = $value;
        }

        return $arguments;
    }

    /**
     * @return array<string, string>
     */
    public function taggedArguments(): array
    {
        $decoded = new PortableValueCodec()->decode(
            $this->encodedTaggedArguments,
        );

        if (!is_array($decoded)) {
            throw new LogicException(
                'The stored tagged arguments are invalid.',
            );
        }

        $arguments = [];

        foreach ($decoded as $name => $tag) {
            if (!is_string($name) || !is_string($tag)) {
                throw new LogicException(
                    'A stored tagged argument is invalid.',
                );
            }

            $arguments[$name] = $tag;
        }

        return $arguments;
    }

    private static function validateIdentifier(string $id): void
    {
        if ($id === '') {
            throw new DefinitionException(
                'A service definition requires a non-empty identifier.',
            );
        }
    }

    private static function validateParameterName(int|string $name): void
    {
        if (!is_string($name) || $name === '') {
            throw new DefinitionException(
                'Constructor argument keys must be non-empty parameter names.',
            );
        }
    }
}
