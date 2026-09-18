<?php

declare(strict_types=1);

namespace Careminate\Application\Runtime;

use Careminate\Application\Exception\RuntimeStateException;
use Careminate\Application\RuntimeInterface;
use Careminate\Container\Container;
use Closure;
use Psr\Container\ContainerInterface;

/**
 * Executes operations sequentially in independent container scopes.
 *
 * @internal
 */
final readonly class ScopedBatchRuntime implements RuntimeInterface
{
    /**
     * @param Closure(): iterable<RuntimeInterface> $operations
     */
    public function __construct(
        private Closure $operations,
    ) {
    }

    public function run(ContainerInterface $container): int
    {
        if (!$container instanceof Container) {
            throw new RuntimeStateException(
                'Scoped batch execution requires a Careminate container.',
            );
        }

        foreach (($this->operations)() as $operation) {
            $result = $container->runInScope(
                static fn (ContainerInterface $resolver): int =>
                    $operation->run($resolver),
            );

            if (!is_int($result)) {
                throw new RuntimeStateException(
                    'A scoped runtime operation must return an integer.',
                );
            }

            if ($result !== 0) {
                return $result;
            }
        }

        return 0;
    }
}
