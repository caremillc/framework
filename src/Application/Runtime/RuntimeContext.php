<?php

declare(strict_types=1);

namespace Careminate\Application\Runtime;

use Careminate\Contracts\Container\ScopedContainerInterface;
use Careminate\Exception\InvalidArgumentException;

final readonly class RuntimeContext
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public RuntimeType $type,
        public float $startedAt,
        public array $input,
        public array $metadata,
        public ScopedContainerInterface $scope,
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException(
                'A runtime context identifier must not be empty.',
            );
        }
    }

    public function elapsedMilliseconds(): float
    {
        return (microtime(true) - $this->startedAt) * 1000;
    }
}
