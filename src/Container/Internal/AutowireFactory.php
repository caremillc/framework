<?php

declare(strict_types=1);

namespace Flint\Container\Internal;

use Closure;
use Flint\Container\Exception\ContainerException;
use Flint\Container\Exception\ResolutionException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

final class AutowireFactory
{
    /**
     * @return Closure(ContainerInterface): object
     */
    public static function create(string $class): Closure
    {
        if (!class_exists($class)) {
            throw new ContainerException(
                sprintf(
                    'Cannot autowire "%s": register an existing concrete class.',
                    $class,
                ),
            );
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException(
                sprintf(
                    'Cannot autowire "%s": the class must be instantiable.',
                    $class,
                ),
            );
        }

        $parameters = $reflection->getConstructor()?->getParameters() ?? [];

        foreach ($parameters as $parameter) {
            self::validateParameter($class, $parameter);
        }

        return static function (ContainerInterface $container) use (
            $class,
            $parameters,
        ): object {
            $arguments = [];

            foreach ($parameters as $parameter) {
                $arguments[] = self::resolveParameter(
                    $container,
                    $class,
                    $parameter,
                );
            }

            return new $class(...$arguments);
        };
    }

    private static function validateParameter(
        string $class,
        ReflectionParameter $parameter,
    ): void {
        $type = $parameter->getType();

        if ($parameter->isVariadic() || $parameter->isPassedByReference()) {
            throw self::unsupportedParameter($class, $parameter);
        }

        if ($type !== null && !$type instanceof ReflectionNamedType) {
            throw self::unsupportedParameter($class, $parameter);
        }

        if (
            $type instanceof ReflectionNamedType
            && in_array($type->getName(), ['self', 'parent'], true)
        ) {
            throw self::unsupportedParameter($class, $parameter);
        }
    }

    private static function resolveParameter(
        ContainerInterface $container,
        string $class,
        ReflectionParameter $parameter,
    ): mixed {
        $type = $parameter->getType();

        if (
            $type instanceof ReflectionNamedType
            && !$type->isBuiltin()
            && $container->has($type->getName())
        ) {
            return $container->get($type->getName());
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new ResolutionException(
            sprintf(
                'Cannot autowire "%s": parameter $%s has no registered dependency or default. Register its type or use an explicit factory.',
                $class,
                $parameter->getName(),
            ),
        );
    }

    private static function unsupportedParameter(
        string $class,
        ReflectionParameter $parameter,
    ): ContainerException {
        return new ContainerException(
            sprintf(
                'Cannot autowire "%s": parameter $%s uses an unsupported constructor signature. Use an explicit factory.',
                $class,
                $parameter->getName(),
            ),
        );
    }
}
