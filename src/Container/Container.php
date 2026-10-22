<?php

declare(strict_types=1);

namespace Careminate\Container;

use Careminate\Container\Exception\ContainerException;
use Careminate\Container\Exception\NotFoundException;
use Closure;
use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use Throwable;

final class Container implements ContainerInterface
{
    /**
     * @var array<string, Closure(self): object>
     */
    private array $factories = [];

    /**
     * @var array<string, true>
     */
    private array $singletons = [];

    /**
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * @var array<string, string>
     */
    private array $aliases = [];

    /**
     * @var array<string, true>
     */
    private array $resolving = [];

    /**
     * @param Closure(self): object $factory
     */
    public function bind(string $id, Closure $factory): void
    {
        $this->register($id, $factory, false);
    }

    /**
     * @param Closure(self): object $factory
     */
    public function singleton(string $id, Closure $factory): void
    {
        $this->register($id, $factory, true);
    }

    public function instance(string $id, object $instance): void
    {
        $this->assertNewIdentifier($id);

        $this->instances[$id] = $instance;
    }

    /**
     * @param class-string $class
     */
    public function autowire(
        string $class,
        bool $singleton = false
    ): void {
        $this->assertNewIdentifier($class);

        if (!class_exists($class)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Autowiring requires an existing concrete class: "%s".',
                    $class
                )
            );
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new InvalidArgumentException(
                sprintf(
                    'Class "%s" is not instantiable.',
                    $class
                )
            );
        }

        $factory = fn (self $container): object =>
        $container->construct($class);

        $this->register($class, $factory, $singleton);
    }

    public function alias(string $alias, string $target): void
    {
        $this->assertNewIdentifier($alias);
        $this->assertIdentifier($target);

        if ($alias === $target) {
            throw new InvalidArgumentException(
                'An alias cannot reference itself.'
            );
        }

        $this->aliases[$alias] = $target;
    }

    public function has(string $id): bool
    {
        $visited = [];

        while (isset($this->aliases[$id])) {
            if (isset($visited[$id])) {
                return false;
            }

            $visited[$id] = true;
            $id = $this->aliases[$id];
        }

        return isset($this->factories[$id])
            || isset($this->instances[$id]);
    }

    public function get(string $id): object
    {
        $canonical = $this->resolveAlias($id);

        if (isset($this->instances[$canonical])) {
            return $this->instances[$canonical];
        }

        if (!isset($this->factories[$canonical])) {
            throw new NotFoundException($canonical);
        }

        if (isset($this->resolving[$canonical])) {
            throw new ContainerException(
                sprintf(
                    'Circular dependency detected while resolving "%s".',
                    $canonical
                )
            );
        }

        $this->resolving[$canonical] = true;

        try {
            try {
                $instance = ($this->factories[$canonical])($this);
            } catch (ContainerExceptionInterface $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new ContainerException(
                    sprintf(
                        'Failed to resolve service "%s".',
                        $canonical
                    ),
                    0,
                    $exception
                );
            }

            if (isset($this->singletons[$canonical])) {
                $this->instances[$canonical] = $instance;
            }

            return $instance;
        } finally {
            unset($this->resolving[$canonical]);
        }
    }

    /**
     * @param class-string $class
     */
    private function construct(string $class): object
    {
        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException(
                sprintf('Class "%s" is not instantiable.', $class)
            );
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                continue;
            }

            $arguments[] = $this->resolveParameter(
                $class,
                $parameter
            );
        }

        return $reflection->newInstanceArgs($arguments);
    }


    /**
     * @param Closure(self): object $factory
     */
    private function register(
        string $id,
        Closure $factory,
        bool $singleton
    ): void {
        $this->assertNewIdentifier($id);

        $this->factories[$id] = $factory;

        if ($singleton) {
            $this->singletons[$id] = true;
        }
    }

    private function resolveAlias(string $id): string
    {
        $visited = [];

        while (isset($this->aliases[$id])) {
            if (isset($visited[$id])) {
                throw new ContainerException(
                    sprintf(
                        'Circular service alias detected at "%s".',
                        $id
                    )
                );
            }

            $visited[$id] = true;
            $id = $this->aliases[$id];
        }

        return $id;
    }

    private function assertNewIdentifier(string $id): void
    {
        $this->assertIdentifier($id);

        if (
            isset($this->factories[$id])
            || isset($this->instances[$id])
            || isset($this->aliases[$id])
        ) {
            throw new InvalidArgumentException(
                sprintf('Service "%s" is already registered.', $id)
            );
        }
    }

    private function assertIdentifier(string $id): void
    {
        if (trim($id) === '') {
            throw new InvalidArgumentException(
                'Service identifier must not be empty.'
            );
        }
    }



    /**
     * @param class-string $owner
     */
    private function resolveParameter(
        string $owner,
        ReflectionParameter $parameter
    ): mixed {
        $type = $parameter->getType();

        if (
            $type instanceof ReflectionUnionType
            || $type instanceof ReflectionIntersectionType
        ) {
            throw new ContainerException(
                sprintf(
                    'Cannot autowire "%s": parameter "$%s" has an unsupported union or intersection type.',
                    $owner,
                    $parameter->getName()
                )
            );
        }

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $dependency = $this->resolveTypeName(
                $type,
                $parameter
            );

            if ($this->has($dependency)) {
                return $this->get($dependency);
            }

            if (class_exists($dependency)) {
                $reflection = new ReflectionClass($dependency);

                if ($reflection->isInstantiable()) {
                    return $this->constructDependency($dependency);
                }
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new ContainerException(
            sprintf(
                'Cannot autowire "%s": parameter "$%s" requires an explicit binding or default value.',
                $owner,
                $parameter->getName()
            )
        );
    }

    private function resolveTypeName(
        ReflectionNamedType $type,
        ReflectionParameter $parameter
    ): string {
        $name = $type->getName();

        if ($name !== 'self' && $name !== 'parent') {
            return $name;
        }

        $declaringClass = $parameter->getDeclaringClass();

        if ($declaringClass === null) {
            throw new ContainerException(
                'Cannot resolve a class-relative constructor type.'
            );
        }

        if ($name === 'self') {
            return $declaringClass->getName();
        }

        $parent = $declaringClass->getParentClass();

        if ($parent === false) {
            throw new ContainerException(
                sprintf(
                    'Cannot resolve parent type for "%s".',
                    $declaringClass->getName()
                )
            );
        }

        return $parent->getName();
    }

    /**
     * @param class-string $class
     */
    private function constructDependency(string $class): object
    {
        if (isset($this->resolving[$class])) {
            throw new ContainerException(
                sprintf(
                    'Circular dependency detected while resolving "%s".',
                    $class
                )
            );
        }

        $this->resolving[$class] = true;

        try {
            return $this->construct($class);
        } finally {
            unset($this->resolving[$class]);
        }
    }
}
