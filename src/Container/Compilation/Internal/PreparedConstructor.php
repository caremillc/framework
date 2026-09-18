<?php

declare(strict_types=1);

namespace Careminate\Container\Compilation\Internal;

use Closure;
use Psr\Container\ContainerInterface;

/**
 * Executes a constructor plan produced by ConstructorPlanCompiler.
 *
 * @internal
 */
final readonly class PreparedConstructor
{
    /**
     * @param class-string<object> $className
     */
    public function __construct(
        public string $className,
        private ArgumentPlan $arguments,
    ) {
    }

    /**
     * @param Closure(string): list<mixed> $taggedResolver
     *
     * @return array<string, mixed>
     */
    public function resolveArguments(
        ContainerInterface $container,
        Closure $taggedResolver,
    ): array {
        return $this->arguments->resolve($container, $taggedResolver);
    }

    /**
     * @param Closure(string): list<mixed> $taggedResolver
     */
    public function __invoke(
        ContainerInterface $container,
        Closure $taggedResolver,
    ): object {
        $arguments = $this->resolveArguments($container, $taggedResolver);
        $class = $this->className;

        return new $class(...$arguments);
    }
}
