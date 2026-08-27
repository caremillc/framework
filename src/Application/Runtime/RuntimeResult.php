<?php

declare(strict_types=1);

namespace Careminate\Application\Runtime;

use Careminate\Exception\InvalidArgumentException;

final readonly class RuntimeResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public int $code,
        public mixed $payload = null,
        public array $metadata = [],
    ) {
        if ($code < 0) {
            throw new InvalidArgumentException(
                'A runtime result code must not be negative.',
            );
        }
    }
}
