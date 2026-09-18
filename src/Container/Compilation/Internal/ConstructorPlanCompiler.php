<?php

declare(strict_types=1);

namespace Careminate\Container\Compilation\Internal;

use Careminate\Container\Compilation\Exception\DefinitionException;
use Careminate\Container\Internal\ConstructorMetadata;

/**
 * @internal
 */
final class ConstructorPlanCompiler
{
    /**
     * @param array<array-key, mixed> $contextBindings
     */
    public function compile(
        ConstructorMetadata $metadata,
        array $contextBindings = [],
    ): PreparedConstructor {
        $contexts = [];

        foreach ($contextBindings as $dependency => $target) {
            if (
                !is_string($dependency)
                || $dependency === ''
                || !is_string($target)
                || $target === ''
            ) {
                throw new DefinitionException(
                    'A compiled contextual binding requires non-empty identifiers.',
                );
            }

            if (!$metadata->supportsDependency($dependency)) {
                throw new DefinitionException(
                    'A compiled contextual dependency does not match the constructor.',
                );
            }

            $contexts[$dependency] = $target;
        }

        $instructions = [];

        foreach ($metadata->parameters as $parameter) {
            $name = $parameter['name'];
            $dependency = $parameter['dependency'];

            if (array_key_exists($name, $metadata->taggedArguments)) {
                $instructions[] = ArgumentInstruction::tagged(
                    $name,
                    $metadata->taggedArguments[$name],
                );

                continue;
            }

            if (array_key_exists($name, $metadata->arguments)) {
                $instructions[] = ArgumentInstruction::literal(
                    $name,
                    $metadata->arguments[$name],
                );

                continue;
            }

            if (
                $dependency !== null
                && array_key_exists($dependency, $contexts)
            ) {
                $instructions[] = ArgumentInstruction::service(
                    $name,
                    $contexts[$dependency],
                );

                continue;
            }

            if ($parameter['inject'] !== null) {
                $instructions[] = ArgumentInstruction::service(
                    $name,
                    $parameter['inject'],
                );

                continue;
            }

            if ($parameter['tag'] !== null) {
                $instructions[] = ArgumentInstruction::tagged(
                    $name,
                    $parameter['tag'],
                );

                continue;
            }

            if ($dependency !== null) {
                $instructions[] = $parameter['default'] === null
                    ? ArgumentInstruction::service($name, $dependency)
                    : ArgumentInstruction::optionalService($name, $dependency);

                continue;
            }

            if ($parameter['default'] !== null) {
                $instructions[] = ArgumentInstruction::omitDefault($name);

                continue;
            }

            throw new DefinitionException(
                'A constructor parameter has no compiled resolution strategy.',
            );
        }

        return new PreparedConstructor(
            $metadata->className,
            new ArgumentPlan(...$instructions),
        );
    }
}
