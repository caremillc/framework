<?php

declare(strict_types=1);

namespace Careminate\Container\Internal;

use Closure;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

/**
 * Resolves and invokes an explicitly registered service constructor.
 *
 * @internal
 */
final class AutowireFactory
{
    private readonly ConstructorMetadata $metadata;

    /**
     * @param array<array-key, mixed> $arguments
     * @param array<array-key, mixed> $taggedArguments
     */
    public function __construct(
        string $class,
        array $arguments = [],
        array $taggedArguments = [],
    ) {
        $this->metadata = new ConstructorMetadata(
            $class,
            $arguments,
            $taggedArguments,
        );
    }

    public function supportsDependency(string $dependency): bool
    {
        return $this->metadata->supportsDependency($dependency);
    }

    /**
     * @param Closure(string): list<mixed> $taggedResolver
     * @param array<string, string> $contextBindings
     */
    public function __invoke(
        ContainerInterface $container,
        Closure $taggedResolver,
        array $contextBindings = [],
    ): object {
        $arguments = $this->resolveArguments(
            $container,
            $taggedResolver,
            $contextBindings,
        );

        $class = $this->metadata->className;

        return new $class(...$arguments);
    }

    /**
     * @param Closure(string): list<mixed> $taggedResolver
     * @param array<string, string> $contextBindings
     *
     * @return list<mixed>
     */
    public function resolveArguments(
        ContainerInterface $container,
        Closure $taggedResolver,
        array $contextBindings = [],
    ): array {
        $arguments = [];

        foreach ($this->metadata->parameters as $parameter) {
            $name = $parameter['name'];

            if (array_key_exists($name, $this->metadata->taggedArguments)) {
                $arguments[] = $taggedResolver(
                    $this->metadata->taggedArguments[$name],
                );

                continue;
            }

            if (array_key_exists($name, $this->metadata->arguments)) {
                $arguments[] = $this->metadata->arguments[$name];

                continue;
            }

            $dependency = $parameter['dependency'];
            $default = $parameter['default'];

            if (
                $dependency !== null
                && array_key_exists($dependency, $contextBindings)
            ) {
                $arguments[] = $container->get($contextBindings[$dependency]);

                continue;
            }

            if ($parameter['inject'] !== null) {
                $arguments[] = $container->get($parameter['inject']);

                continue;
            }

            if ($parameter['tag'] !== null) {
                $arguments[] = $taggedResolver($parameter['tag']);

                continue;
            }

            if (
                $dependency !== null
                && ($default === null || $container->has($dependency))
            ) {
                $arguments[] = $container->get($dependency);

                continue;
            }

            if ($default === null) {
                throw new InvalidArgumentException(
                    'The constructor parameter has no resolution strategy.',
                );
            }

            $arguments[] = $default->getDefaultValue();
        }

        return $arguments;
    }
}
