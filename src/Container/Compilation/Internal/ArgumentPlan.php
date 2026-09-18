<?php

declare(strict_types=1);

namespace Careminate\Container\Compilation\Internal;

use Careminate\Container\Compilation\Exception\DefinitionException;
use Closure;
use Psr\Container\ContainerInterface;

/**
 * @internal
 */
final readonly class ArgumentPlan
{
    /**
     * @var list<ArgumentInstruction>
     */
    private array $instructions;

    public function __construct(ArgumentInstruction ...$instructions)
    {
        $seen = [];

        foreach ($instructions as $instruction) {
            $key = 'parameter:' . $instruction->parameter;

            if (isset($seen[$key])) {
                throw new DefinitionException(
                    'A constructor parameter has multiple argument instructions.',
                );
            }

            $seen[$key] = true;
        }

        $this->instructions = array_values($instructions);
    }

    /**
     * @param Closure(string): list<mixed> $taggedResolver
     *
     * @return array<string, mixed>
     */
    public function resolve(
        ContainerInterface $container,
        Closure $taggedResolver,
    ): array {
        $arguments = [];

        foreach ($this->instructions as $instruction) {
            switch ($instruction->action) {
                case ArgumentAction::Literal:
                    $arguments[$instruction->parameter] = $instruction->value;

                    break;

                case ArgumentAction::Service:
                    $arguments[$instruction->parameter] = $container->get(
                        $instruction->target,
                    );

                    break;

                case ArgumentAction::Tagged:
                    $arguments[$instruction->parameter] = $taggedResolver(
                        $instruction->target,
                    );

                    break;

                case ArgumentAction::OptionalService:
                    if ($container->has($instruction->target)) {
                        $arguments[$instruction->parameter] = $container->get(
                            $instruction->target,
                        );
                    }

                    break;

                case ArgumentAction::OmitDefault:
                    break;
            }
        }

        return $arguments;
    }
}
