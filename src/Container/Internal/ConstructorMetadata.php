<?php

declare(strict_types=1);

namespace Careminate\Container\Internal;

use Careminate\Container\Attribute\Inject;
use Careminate\Container\Attribute\Tagged;
use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Validated constructor discovery shared by autowiring and compilation.
 *
 * This object contains reflection metadata and is not portable.
 *
 * @internal
 */
final readonly class ConstructorMetadata
{
    /**
     * @var class-string<object>
     */
    public string $className;

    /**
     * @var list<array{
     *     name: string,
     *     dependency: string|null,
     *     default: ReflectionParameter|null,
     *     inject: string|null,
     *     tag: string|null
     * }>
     */
    public array $parameters;

    /**
     * @var array<array-key, mixed>
     */
    public array $arguments;

    /**
     * @var array<string, string>
     */
    public array $taggedArguments;

    /**
     * @param array<array-key, mixed> $arguments
     * @param array<array-key, mixed> $taggedArguments
     */
    public function __construct(
        string $class,
        array $arguments = [],
        array $taggedArguments = [],
    ) {
        foreach (array_keys($arguments) as $name) {
            if (!is_string($name) || $name === '') {
                throw new InvalidArgumentException(
                    'Constructor argument keys must be non-empty parameter names.',
                );
            }
        }

        $tags = [];

        foreach ($taggedArguments as $name => $tag) {
            if (!is_string($name) || $name === '') {
                throw new InvalidArgumentException(
                    'Tagged argument keys must be non-empty parameter names.',
                );
            }

            if (!is_string($tag) || $tag === '') {
                throw new InvalidArgumentException(
                    'Tagged arguments must reference non-empty tag names.',
                );
            }

            if (array_key_exists($name, $arguments)) {
                throw new InvalidArgumentException(
                    'A parameter cannot have both a literal and tagged argument.',
                );
            }

            $tags[$name] = $tag;
        }

        if (!class_exists($class)) {
            throw new InvalidArgumentException(
                'Autowiring requires an existing concrete class.',
            );
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new InvalidArgumentException(
                'The autowired class must be instantiable.',
            );
        }

        $parameters = [];
        $remainingArguments = $arguments;
        $remainingTags = $tags;
        $constructor = $reflection->getConstructor();

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                if ($parameter->isPassedByReference()) {
                    throw new InvalidArgumentException(
                        'Autowiring does not support reference parameters.',
                    );
                }

                if ($parameter->isVariadic()) {
                    throw new InvalidArgumentException(
                        'Autowiring does not support variadic parameters.',
                    );
                }

                $name = $parameter->getName();
                $type = $parameter->getType();
                $dependency = null;
                $hasTag = array_key_exists($name, $tags);
                $source = self::attributeSources($parameter);

                $hasAttributeSource = $source['inject'] !== null
                    || $source['tag'] !== null;

                $default = $parameter->isDefaultValueAvailable()
                    ? $parameter
                    : null;

                if (
                    ($hasTag || $source['tag'] !== null)
                    && (
                        !$type instanceof ReflectionNamedType
                        || $type->getName() !== 'array'
                    )
                ) {
                    throw new InvalidArgumentException(
                        'A tagged argument requires an array parameter.',
                    );
                }

                if (
                    $type !== null
                    && !$type instanceof ReflectionNamedType
                    && $default === null
                    && !array_key_exists($name, $arguments)
                    && !$hasAttributeSource
                ) {
                    throw new InvalidArgumentException(
                        'A composite parameter requires an explicit value or declared default.',
                    );
                }

                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    $dependency = self::dependencyIdentifier($parameter, $type);
                }

                if (
                    $dependency === null
                    && $default === null
                    && !array_key_exists($name, $arguments)
                    && !$hasTag
                    && !$hasAttributeSource
                ) {
                    throw new InvalidArgumentException(
                        'A primitive or untyped parameter requires an explicit value or declared default.',
                    );
                }

                $parameters[] = [
                    'name' => $name,
                    'dependency' => $dependency,
                    'default' => $default,
                    'inject' => $source['inject'],
                    'tag' => $source['tag'],
                ];

                unset($remainingArguments[$name], $remainingTags[$name]);
            }
        }

        if ($remainingArguments !== []) {
            throw new InvalidArgumentException(
                'An explicit argument does not match a constructor parameter.',
            );
        }

        if ($remainingTags !== []) {
            throw new InvalidArgumentException(
                'A tagged argument does not match a constructor parameter.',
            );
        }

        $this->className = $reflection->getName();
        $this->parameters = $parameters;
        $this->arguments = $arguments;
        $this->taggedArguments = $tags;
    }

    public function supportsDependency(string $dependency): bool
    {
        foreach ($this->parameters as $parameter) {
            if ($parameter['dependency'] === $dependency) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{inject: string|null, tag: string|null}
     */
    private static function attributeSources(
        ReflectionParameter $parameter,
    ): array {
        /** @var list<ReflectionAttribute<Inject>> $injectAttributes */
        $injectAttributes = $parameter->getAttributes(Inject::class);

        /** @var list<ReflectionAttribute<Tagged>> $tagAttributes */
        $tagAttributes = $parameter->getAttributes(Tagged::class);

        if (count($injectAttributes) + count($tagAttributes) > 1) {
            throw new InvalidArgumentException(
                'A constructor parameter may declare only one injection attribute.',
            );
        }

        $injectAttribute = $injectAttributes[0] ?? null;
        $tagAttribute = $tagAttributes[0] ?? null;

        return [
            'inject' => $injectAttribute?->newInstance()->id,
            'tag' => $tagAttribute?->newInstance()->tag,
        ];
    }

    private static function dependencyIdentifier(
        ReflectionParameter $parameter,
        ReflectionNamedType $type,
    ): string {
        $name = $type->getName();

        if ($name !== 'self' && $name !== 'parent') {
            return $name;
        }

        $declaringClass = $parameter->getDeclaringClass();

        if ($declaringClass === null) {
            throw new InvalidArgumentException(
                'The constructor parameter has no declaring class.',
            );
        }

        if ($name === 'self') {
            return $declaringClass->getName();
        }

        $parent = $declaringClass->getParentClass();

        if ($parent === false) {
            throw new InvalidArgumentException(
                'The constructor parameter has no parent class.',
            );
        }

        return $parent->getName();
    }
}
