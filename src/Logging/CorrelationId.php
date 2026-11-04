<?php

declare(strict_types=1);

namespace Careminate\Logging;

use InvalidArgumentException;

final readonly class CorrelationId
{
    private function __construct(
        public string $value,
    ) {
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }

    public static function fromString(string $value): self
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $value) !== 1) {
            throw new InvalidArgumentException(
                'Correlation ID must contain 32 lowercase hexadecimal characters.'
            );
        }

        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
