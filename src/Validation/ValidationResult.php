<?php

declare(strict_types=1);

namespace Careminate\Validation;

use LogicException;

final readonly class ValidationResult
{
    /**
     * @param array<string, mixed> $values
     * @param array<string, list<string>> $errors
     */
    public function __construct(
        private array $values,
        private array $errors,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Returns only declared, validated fields.
     * No partial result is exposed.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        if (! $this->isValid()) {
            throw new LogicException(
                'Cannot access validated data when validation failed.',
            );
        }

        return $this->values;
    }
}
