<?php 
namespace Careminate\Container;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

class Container implements ContainerInterface
{
    private array $services = [];

    /**
     * Add a service to the container.
     *
     * @param string $id
     * @param string|object $concrete
     */
    public function add(string $id, $concrete = null)
    {
        if (null === $concrete) {
            if (!class_exists($id)) {
                throw new ContainerException("Service '$id' could not be added: Class not found.");
            }

            $concrete = $id;
        }

        $this->services[$id] = $concrete;
    }

    /**
     * Retrieve a service from the container.
     *
     * @param string $id
     * @return object
     * @throws ContainerException
     */
    public function get(string $id)
    {
        if (!$this->has($id)) {
            if (!class_exists($id)) {
                throw new ContainerException("Service '$id' could not be resolved: Service not found.");
            }

            $this->add($id); // This might be a class name that hasn't been added yet
        }

        $object = $this->resolve($this->services[$id]);
        return $object;
    }

    /**
     * Resolves a class or object and injects its dependencies.
     *
     * @param string|object $class
     * @return object
     * @throws ContainerException
     */
    private function resolve($class): object
    {
        // Instantiate a Reflection class (dump and check)
        $reflectionClass = new ReflectionClass($class);

        // Check if the class is instantiable
        if (!$reflectionClass->isInstantiable()) {
            throw new ContainerException("Class '$class' cannot be instantiated.");
        }

        // Use Reflection to get the class constructor
        $constructor = $reflectionClass->getConstructor();

        // If there's no constructor, return an instance of the class
        if (null === $constructor) {
            return $reflectionClass->newInstance();
        }

        // Get constructor parameters
        $constructorParams = $constructor->getParameters();

        // Resolve the class dependencies
        $classDependencies = $this->resolveClassDependencies($constructorParams);

        // Instantiate the class with resolved dependencies
        return $reflectionClass->newInstanceArgs($classDependencies);
    }

    /**
     * Resolve the dependencies for a class constructor's parameters.
     *
     * @param ReflectionParameter[] $reflectionParameters
     * @return array
     * @throws ContainerException
     */
    private function resolveClassDependencies(array $reflectionParameters): array
    {
        // Initialize an array to store dependencies
        $classDependencies = [];

        foreach ($reflectionParameters as $parameter) {
            $serviceType = $parameter->getType();

            // Check if parameter has a class type
            if ($serviceType instanceof ReflectionNamedType && !$serviceType->isBuiltin()) {
                // If it's a class, resolve it
                $classDependencies[] = $this->get($serviceType->getName());
            } else {
                // If it's not a class (i.e., a primitive or null), we can't resolve it here
                $classDependencies[] = $this->resolvePrimitiveDependency($parameter);
            }
        }

        return $classDependencies;
    }

    /**
     * Handles primitive dependencies (e.g., strings, integers, etc.)
     *
     * @param ReflectionParameter $parameter
     * @return mixed
     */
    private function resolvePrimitiveDependency(ReflectionParameter $parameter)
    {
        $paramName = $parameter->getName();

        // For now, if we don't handle it directly, throw an exception
        throw new ContainerException("Cannot resolve non-class dependency '$paramName' of type '{$parameter->getType()}'.");
    }

    /**
     * Check if a service is registered in the container.
     *
     * @param string $id
     * @return bool
     */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services);
    }
}
