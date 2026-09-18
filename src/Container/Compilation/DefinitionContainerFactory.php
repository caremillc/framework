<?php

declare(strict_types=1);

namespace Careminate\Container\Compilation;

use Careminate\Container\Compilation\Exception\DefinitionException;
use Careminate\Container\Container;

/**
 * @internal
 */
final class DefinitionContainerFactory
{
    public function create(
        DefinitionSet $definitions,
        bool $freeze = true,
    ): Container {
        $definitions = $this->validateRelationships($definitions);
        $container = new Container();

        foreach ($definitions->services as $definition) {
            $this->registerService($container, $definition);
        }

        foreach ($definitions->aliases as $alias) {
            $container->alias($alias['alias'], $alias['target']);
        }

        foreach ($definitions->tags as $tag) {
            $container->tag($tag['name'], ...$tag['identifiers']);
        }

        foreach ($definitions->contexts as $context) {
            $container->bindContext(
                $context['consumer'],
                $context['dependency'],
                $context['target'],
            );
        }

        if ($freeze) {
            $container->freeze();
        }

        return $container;
    }

    private function validateRelationships(
        DefinitionSet $definitions,
    ): DefinitionSet {
        $builder = new DefinitionBuilder();

        foreach ($definitions->services as $definition) {
            $builder->add($definition);
        }

        foreach ($definitions->aliases as $alias) {
            $builder->alias($alias['alias'], $alias['target']);
        }

        foreach ($definitions->tags as $tag) {
            $builder->tag($tag['name'], ...$tag['identifiers']);
        }

        foreach ($definitions->contexts as $context) {
            $builder->bindContext(
                $context['consumer'],
                $context['dependency'],
                $context['target'],
            );
        }

        return $builder->build();
    }

    private function registerService(
        Container $container,
        ServiceDefinition $definition,
    ): void {
        $className = $definition->className;

        if ($className === null) {
            $container->register($definition->id, $definition->value());

            return;
        }

        $arguments = $definition->arguments();
        $taggedArguments = $definition->taggedArguments();

        if ($definition->lazy) {
            $container->lazySingleton(
                $definition->id,
                $className,
                arguments: $arguments,
                taggedArguments: $taggedArguments,
            );

            return;
        }

        switch ($definition->lifetime) {
            case DefinitionLifetime::Transient:
                $container->autowire(
                    $definition->id,
                    $className,
                    arguments: $arguments,
                    taggedArguments: $taggedArguments,
                );

                return;

            case DefinitionLifetime::Singleton:
                $container->autowire(
                    $definition->id,
                    $className,
                    shared: true,
                    arguments: $arguments,
                    taggedArguments: $taggedArguments,
                );

                return;

            case DefinitionLifetime::Scoped:
                $container->scopedAutowire(
                    $definition->id,
                    $className,
                    arguments: $arguments,
                    taggedArguments: $taggedArguments,
                );

                return;

            default:
                throw new DefinitionException(
                    'An autowired definition requires a lifetime.',
                );
        }
    }
}
