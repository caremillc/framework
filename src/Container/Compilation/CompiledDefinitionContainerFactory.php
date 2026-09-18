<?php

declare(strict_types=1);

namespace Careminate\Container\Compilation;

use Careminate\Container\Compilation\Exception\DefinitionException;
use Careminate\Container\Compilation\Internal\ConstructorPlanCompiler;
use Careminate\Container\Compilation\Internal\PreparedConstructor;
use Careminate\Container\Container;
use Careminate\Container\Internal\ConstructorMetadata;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

/**
 * Prepares constructor plans and registers them in a frozen container.
 *
 * @internal
 */
final class CompiledDefinitionContainerFactory
{
    /**
     * @param array<string, object> $runtimeObjects
     */
    public function create(
        DefinitionSet $definitions,
        array $runtimeObjects = [],
    ): Container {
        $definitions = $this->validateRelationships($definitions);
        $plans = $this->prepareConstructors($definitions);
        $container = new Container();

        foreach ($definitions->services as $definition) {
            if ($definition->isValue()) {
                $container->register(
                    $definition->id,
                    $definition->value(),
                );

                continue;
            }

            $prepared = $plans['entry:' . $definition->id];

            if ($definition->lazy) {
                $container->preparedLazySingleton(
                    $definition->id,
                    $prepared->className,
                    static fn (ContainerInterface $resolver): array =>
                        $prepared->resolveArguments(
                            $resolver,
                            $container->tagged(...),
                        ),
                );

                continue;
            }

            $factory = static fn (ContainerInterface $resolver): object => $prepared(
                $resolver,
                $container->tagged(...),
            );

            switch ($definition->lifetime) {
                case DefinitionLifetime::Transient:
                    $container->factory($definition->id, $factory);

                    break;

                case DefinitionLifetime::Singleton:
                    $container->singleton($definition->id, $factory);

                    break;

                case DefinitionLifetime::Scoped:
                    $container->scoped($definition->id, $factory);

                    break;

                default:
                    throw new DefinitionException(
                        'A compiled service requires a lifetime.',
                    );
            }
        }

        foreach ($definitions->aliases as $alias) {
            $container->alias($alias['alias'], $alias['target']);
        }

        foreach ($definitions->tags as $tag) {
            $container->tag($tag['name'], ...$tag['identifiers']);
        }

        foreach ($runtimeObjects as $identifier => $object) {
            $container->register($identifier, $object);
        }

        $container->freeze();

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

    /**
     * @return array<string, PreparedConstructor>
     */
    private function prepareConstructors(DefinitionSet $definitions): array
    {
        /** @var array<string, array<string, string>> $contexts */
        $contexts = [];

        foreach ($definitions->contexts as $context) {
            $contexts['entry:' . $context['consumer']][$context['dependency']]
                = $context['target'];
        }

        $compiler = new ConstructorPlanCompiler();
        $plans = [];

        foreach ($definitions->services as $definition) {
            $className = $definition->className;

            if ($className === null) {
                continue;
            }

            $key = 'entry:' . $definition->id;

            try {
                $metadata = new ConstructorMetadata(
                    $className,
                    $definition->arguments(),
                    $definition->taggedArguments(),
                );
            } catch (InvalidArgumentException $previous) {
                throw new DefinitionException(
                    message: 'A compiled constructor could not be prepared.',
                    previous: $previous,
                );
            }

            $plans[$key] = $compiler->compile(
                $metadata,
                $contexts[$key] ?? [],
            );
        }

        return $plans;
    }
}
