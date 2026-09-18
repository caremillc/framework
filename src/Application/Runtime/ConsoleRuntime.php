<?php

declare(strict_types=1);

namespace Careminate\Application\Runtime;

use Careminate\Application\Exception\InvalidExitStatusException;
use Careminate\Application\RuntimeInterface;
use Closure;
use Psr\Container\ContainerInterface;

/**
 * Adapts a console operation to the application runtime boundary.
 *
 * @api
 */
final readonly class ConsoleRuntime implements RuntimeInterface
{
    /**
     * @param Closure(ContainerInterface): int $operation
     */
    public function __construct(
        private Closure $operation,
    ) {
    }

    public function run(ContainerInterface $container): int
    {
        $status = ($this->operation)($container);

        if ($status < 0 || $status > 255) {
            throw new InvalidExitStatusException(
                'A console exit status must be between 0 and 255.',
            );
        }

        return $status;
    }
}
