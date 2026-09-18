<?php

declare(strict_types=1);

namespace Careminate\Container\Internal;

use Closure;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;

/**
 * Native lazy-ghost allocation and strict constructor invocation.
 *
 * Container lifecycle guards must surround initialization.
 *
 * @internal
 */
final class LazyClass
{
    /**
     * @var ReflectionClass<object>
     */
    private readonly ReflectionClass $reflection;

    public function __construct(string $class)
    {
        if (!class_exists($class)) {
            throw new InvalidArgumentException(
                'A lazy service requires an existing concrete class.',
            );
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new InvalidArgumentException(
                'The lazy service class must be instantiable.',
            );
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null || !$constructor->isPublic()) {
            throw new InvalidArgumentException(
                'A lazy service requires a public constructor.',
            );
        }

        $current = $reflection;
        $hasState = false;

        while ($current !== false) {
            if ($current->isInternal()) {
                throw new InvalidArgumentException(
                    'Lazy services require a user-defined class hierarchy.',
                );
            }

            foreach ($current->getProperties() as $property) {
                if (!$property->isStatic() && !$property->isVirtual()) {
                    $hasState = true;
                }
            }

            $current = $current->getParentClass();
        }

        if (!$hasState) {
            throw new InvalidArgumentException(
                'A lazy service requires a backed instance property.',
            );
        }

        $this->reflection = $reflection;
    }

    /**
     * @param Closure(object): void $initializer
     */
    public function create(Closure $initializer): object
    {
        $object = $this->reflection->newLazyGhost($initializer);

        if (!$this->reflection->isUninitializedLazyObject($object)) {
            throw new LogicException(
                'The lazy service could not be created in an uninitialized state.',
            );
        }

        return $object;
    }

    /**
     * Invoke only from the native ghost initializer.
     *
     * Supply either positional arguments or named arguments.
     * Named arguments may omit parameters with declared defaults.
     *
     * @param array<array-key, mixed> $arguments
     */
    public function initialize(object $object, array $arguments): void
    {
        if ($object::class !== $this->reflection->getName()) {
            throw new InvalidArgumentException(
                'The initialization target has an unexpected class.',
            );
        }

        $constructor = [$object, '__construct'];

        if (!is_callable($constructor)) {
            throw new LogicException(
                'The lazy service constructor is not callable.',
            );
        }

        $constructor(...$arguments);
    }

    public function isUninitialized(object $object): bool
    {
        return $this->reflection->isUninitializedLazyObject($object);
    }
}
