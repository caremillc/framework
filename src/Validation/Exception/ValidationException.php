<?php

declare (strict_types=1);

namespace Careminate\Validation\Exception;

use Careminate\Validation\ValidationResult;
use InvalidArgumentException;
use RuntimeException;

/**
 * Represents rejected application fields, not malformed JSON.
 * Only documented error codes and schema-derived paths are accepted.
 */
final class ValidationException extends RuntimeException
{
    private const array FIELD_CODES = [
        'required',
        'type',
        'encoding',
        'blank',
        'min_length',
        'max_length',
        'max_bytes',
        'min',
        'max',
        'enum',
        'min_items',
        'max_items',
        'unique_items',
    ];

    /** @var array<string, list<string>> */
    private readonly array $errors;

    public function __construct(ValidationResult $result)
    {
        if ($result->isValid()) {
            throw new InvalidArgumentException(
                'Cannot create a validation exception from a valid result.',
            );
        }

        $errors = $result->errors();

        foreach ($errors as $field => $codes) {
            if (
                strlen($field) > 256
                || preg_match(
                    '/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*|\[[0-9]+\])*\z/',
                    $field,
                ) !== 1
                || $codes === []
            ) {
                throw new InvalidArgumentException(
                    'Invalid validation error field.',
                );
            }

            // _input reports unexpected field names without reproducing them.
            // Nested markers use paths such as profile._input
            // or tasks[0]._input.
            if (
                $field === '_input'
                || str_ends_with($field, '._input')
            ) {
                if ($codes !== ['unknown_fields']) {
                    throw new InvalidArgumentException(
                        'Invalid request-level validation error.',
                    );
                }

                continue;
            }

            foreach ($codes as $code) {
                if (! in_array($code, self::FIELD_CODES, true)) {
                    throw new InvalidArgumentException(
                        'Unsupported validation error code.',
                    );
                }
            }
        }

        $this->errors = $errors;

        parent::__construct('Input validation failed.', 422);
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }
}
