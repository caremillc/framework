<?php
declare(strict_types=1);

namespace Careminate\Container;

use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionParameter;
use Closure;

class Container implements ContainerInterface
{
    protected array $bindings = [];
    protected array $singletons = [];
    protected array $aliases = [];
    protected array $parameters = [];
    protected array $resolved = [];

    public function __construct(array $config = [])
    {
        $this->parameters = $config;
    }

    public function get(string $id)
    {
        if (isset($this->aliases[$id])) {
            return $this->get($this->aliases[$id]);
        }

        // Check if it's a singleton
        if (isset($this->singletons[$id])) {
            return $this->singletons[$id];
        }

        // Check if it's a binding
        if (isset($this->bindings[$id])) {
            return $this->resolveBinding($id);
        }

        // If service not found in bindings, resolve via autowiring
        return $this->resolveService($id);
    }

    public function has(string $id): bool
    {
        return isset($this->bindings[$id]) ||
               isset($this->singletons[$id]) ||
               isset($this->aliases[$id]) ||
               class_exists($id);
    }

    public function bind(string $abstract, $concrete = null, bool $shared = false): void
    {
        if (is_null($concrete)) {
            $concrete = $abstract;
        }

        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'shared' => $shared
        ];
    }

    public function singleton(string $abstract, $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    public function alias(string $abstract, string $alias): void
    {
        $this->aliases[$alias] = $abstract;
    }

    public function config(string $key, $value = null)
    {
        if (is_null($value)) {
            return $this->parameters[$key] ?? null;
        }

        $this->parameters[$key] = $value;
    }

    public function add(string $id, $concrete = null)
    {
        if (is_null($concrete)) {
            if (!class_exists($id)) {
                throw new ContainerException("Service $id could not be added");
            }
            $concrete = $id;
        }

        $this->bindings[$id] = $concrete;
    }

    protected function resolveBinding(string $abstract)
    {
        $binding = $this->bindings[$abstract];

        if ($binding instanceof Closure) {
            $object = $binding($this);
        } else {
            $object = $this->resolveService($binding);
        }

        // If the binding is shared, save it as a singleton
        if (isset($this->bindings[$abstract]['shared']) && $this->bindings[$abstract]['shared']) {
            $this->singletons[$abstract] = $object;
        }

        return $object;
    }

    protected function resolveService(string $class)
    {
        if (!class_exists($class)) {
            throw new ContainerException("Class {$class} does not exist");
        }

        $reflector = new ReflectionClass($class);

        if (!$reflector->isInstantiable()) {
            throw new ContainerException("Class {$class} is not instantiable");
        }

        $constructor = $reflector->getConstructor();

        if (is_null($constructor)) {
            return new $class();
        }

        $parameters = $constructor->getParameters();
        $dependencies = $this->resolveDependencies($parameters);

        return $reflector->newInstanceArgs($dependencies);
    }

    protected function resolveDependencies(array $parameters): array
    {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $dependency = $parameter->getType();

            if (is_null($dependency)) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } else {
                    throw new ContainerException(
                        "Cannot resolve parameter \${$parameter->name} in {$parameter->getDeclaringClass()->getName()}"
                    );
                }
            } else {
                $dependencies[] = $this->get($dependency->getName());
            }
        }

        return $dependencies;
    }

    public function resolveClassDependencies(array $reflectionParameters): array
    {
        $classDependencies = [];

        foreach ($reflectionParameters as $parameter) {
            $serviceType = $parameter->getType();

            if ($serviceType) {
                $service = $this->get($serviceType->getName());
                $classDependencies[] = $service;
            }
        }

        return $classDependencies;
    }
}
