<?php

declare(strict_types=1);

namespace Careminate\Validation;

use InvalidArgumentException;

/**
 * Strict, non-coercing validation for decoded JSON-like PHP arrays.
 *
 * Object rules contain `fields`; list rules contain `items`.
 * Unknown object keys are rejected by default at every level.
 */
final class Validator
{
    private const int MAX_SCHEMA_DEPTH = 16;
    private const int MAX_ERROR_PATH_BYTES = 256;

    /**
     * Validate decoded request data against a developer-defined schema.
     *
     * The entire schema is checked before any input is validated.
     *
     * @param array<array-key, mixed> $input
     * @param array<string, array<string, mixed>> $schema
     */
    public function validate(
        array $input,
        array $schema,
        bool $rejectUnknown = true,
    ): ValidationResult {
        $this->assertFields($schema, '', 0);

        /** @var array<string, list<string>> $errors */
        $errors = [];

        $validated = $this->validateFields(
            $input,
            $schema,
            '',
            $rejectUnknown,
            $errors,
        );

        return new ValidationResult(
            $validated,
            $errors,
        );
    }

    /**
     * Validate the fields of an object.
     *
     * @param array<array-key, mixed> $input
     * @param array<string, array<string, mixed>> $schema
     * @param array<string, list<string>> $errors
     *
     * @return array<string, mixed>
     */
    private function validateFields(
        array $input,
        array $schema,
        string $prefix,
        bool $rejectUnknown,
        array &$errors,
    ): array {
        /** @var array<string, mixed> $validated */
        $validated = [];

        if ($rejectUnknown) {
            foreach ($input as $key => $_value) {
                if (
                    ! is_string($key)
                    || ! array_key_exists($key, $schema)
                ) {
                    $marker = $prefix === ''
                        ? '_input'
                        : $prefix . '._input';

                    $errors[$marker] = ['unknown_fields'];

                    break;
                }
            }
        }

        foreach ($schema as $field => $rules) {
            $path = $prefix === ''
                ? $field
                : $prefix . '.' . $field;

            // Missing fields are distinct from fields containing null.
            if (! array_key_exists($field, $input)) {
                if (($rules['required'] ?? false) === true) {
                    $errors[$path] = ['required'];
                }

                continue;
            }

            $value = $input[$field];

            if ($value === null) {
                if (($rules['nullable'] ?? false) === true) {
                    $validated[$field] = null;
                } else {
                    $errors[$path] = ['type'];
                }

                continue;
            }

            $validated[$field] = $this->validateValue(
                $value,
                $rules,
                $path,
                $rejectUnknown,
                $errors,
            );
        }

        return $validated;
    }

    /**
     * Validate an individual scalar, object or array value.
     *
     * @param array<string, mixed> $rules
     * @param array<string, list<string>> $errors
     */
    private function validateValue(
        mixed $value,
        array $rules,
        string $path,
        bool $rejectUnknown,
        array &$errors,
    ): mixed {
        $type = $rules['type'];

        /*
         * STRING VALIDATION
         *
         * Encoding is validated first.
         * Extended constraints only run for valid UTF-8.
         */
        if ($type === 'string') {
            if (! is_string($value)) {
                $errors[$path] = ['type'];

                return null;
            }

            if (preg_match('//u', $value) !== 1) {
                $errors[$path] = ['encoding'];

                return $value;
            }

            $length = preg_match_all('/./us', $value);

            if ($length === false) {
                $errors[$path] = ['encoding'];

                return $value;
            }

            /** @var list<string> $fieldErrors */
            $fieldErrors = [];

            if (
                ($rules['notBlank'] ?? false) === true
                && preg_match('/(*UCP)\S/u', $value) !== 1
            ) {
                $fieldErrors[] = 'blank';
            }

            if (
                isset($rules['minLength'])
                && $length < $rules['minLength']
            ) {
                $fieldErrors[] = 'min_length';
            }

            if (
                isset($rules['maxLength'])
                && $length > $rules['maxLength']
            ) {
                $fieldErrors[] = 'max_length';
            }

            // maxBytes and strict string enum constraints.
            // Only reached after successful UTF-8 validation.
            $fieldErrors = array_merge(
                $fieldErrors,
                ExtendedRules::scalarErrors(
                    $value,
                    $rules,
                ),
            );

            if ($fieldErrors !== []) {
                $errors[$path] = $fieldErrors;
            }

            return $value;
        }

        /*
         * INTEGER VALIDATION
         *
         * No conversion from numeric strings, floats or booleans.
         */
        if ($type === 'integer') {
            if (! is_int($value)) {
                $errors[$path] = ['type'];

                return null;
            }

            /** @var list<string> $fieldErrors */
            $fieldErrors = [];

            if (
                isset($rules['min'])
                && $value < $rules['min']
            ) {
                $fieldErrors[] = 'min';
            }

            if (
                isset($rules['max'])
                && $value > $rules['max']
            ) {
                $fieldErrors[] = 'max';
            }

            // Integer enum constraints must be checked regardless
            // of whether min or max is configured.
            $fieldErrors = array_merge(
                $fieldErrors,
                ExtendedRules::scalarErrors(
                    $value,
                    $rules,
                ),
            );

            if ($fieldErrors !== []) {
                $errors[$path] = $fieldErrors;
            }

            return $value;
        }

        /*
         * BOOLEAN VALIDATION
         */
        if ($type === 'boolean') {
            if (! is_bool($value)) {
                $errors[$path] = ['type'];

                return null;
            }

            $fieldErrors = ExtendedRules::scalarErrors(
                $value,
                $rules,
            );

            if ($fieldErrors !== []) {
                $errors[$path] = $fieldErrors;
            }

            return $value;
        }

        /*
         * OBJECT VALIDATION
         *
         * Associative json_decode() converts both {} and []
         * into an empty PHP array. This ambiguity is retained
         * for compatibility with the current JSON parser.
         */
        if ($type === 'object') {
            if (
                ! is_array($value)
                || (
                    $value !== []
                    && array_is_list($value)
                )
            ) {
                $errors[$path] = ['type'];

                return null;
            }

            /** @var array<string, array<string, mixed>> $fields */
            $fields = $rules['fields'];

            return $this->validateFields(
                $value,
                $fields,
                $path,
                $rejectUnknown,
                $errors,
            );
        }

        /*
         * ARRAY VALIDATION
         *
         * Supports primitive items, nested arrays and objects.
         */
        if ($type === 'array') {
            if (
                ! is_array($value)
                || ! array_is_list($value)
            ) {
                $errors[$path] = ['type'];

                return null;
            }

            /** @var list<string> $fieldErrors */
            $fieldErrors = [];

            $count = count($value);

            if (
                isset($rules['minItems'])
                && $count < $rules['minItems']
            ) {
                $fieldErrors[] = 'min_items';
            }

            if (
                isset($rules['maxItems'])
                && $count > $rules['maxItems']
            ) {
                $fieldErrors[] = 'max_items';
            }

            if (
                ($rules['uniqueItems'] ?? false) === true
                && ExtendedRules::hasDuplicateScalarItems(
                    $value,
                )
            ) {
                $fieldErrors[] = 'unique_items';
            }

            if ($fieldErrors !== []) {
                $errors[$path] = $fieldErrors;
            }

            /** @var array<string, mixed> $itemRules */
            $itemRules = $rules['items'];

            $items = [];

            foreach ($value as $index => $item) {
                $itemPath = $path . '[' . $index . ']';

                if ($item === null) {
                    if (
                        ($itemRules['nullable'] ?? false) === true
                    ) {
                        $items[] = null;
                    } else {
                        $errors[$itemPath] = ['type'];
                        $items[] = null;
                    }

                    continue;
                }

                $items[] = $this->validateValue(
                    $item,
                    $itemRules,
                    $itemPath,
                    $rejectUnknown,
                    $errors,
                );
            }

            return $items;
        }

        // assertRule() rejects unsupported types before input processing.
        throw new InvalidArgumentException(
            'Unsupported validation field type.',
        );
    }

    /**
     * Validate a complete object-field schema.
     *
     * @param array<array-key, mixed> $schema
     */
    private function assertFields(
        array $schema,
        string $prefix,
        int $depth,
    ): void {
        if ($depth > self::MAX_SCHEMA_DEPTH) {
            throw new InvalidArgumentException(
                'Validation schema exceeds maximum nesting depth.',
            );
        }

        foreach ($schema as $field => $rules) {
            if (
                ! is_string($field)
                || strlen($field) > 64
                || preg_match(
                    '/\A[A-Za-z_][A-Za-z0-9_]*\z/D',
                    $field,
                ) !== 1
                || $field === '_input'
            ) {
                throw new InvalidArgumentException(
                    'Invalid validation field name.',
                );
            }

            if (! is_array($rules)) {
                throw new InvalidArgumentException(
                    'Invalid validation field rules.',
                );
            }

            $path = $prefix === ''
                ? $field
                : $prefix . '.' . $field;

            $this->assertPath($path);

            /** @var array<string, mixed> $rules */
            $this->assertRule(
                $rules,
                $path,
                $depth,
            );
        }
    }

    /**
     * Validate one schema node and all its descendants.
     *
     * @param array<string, mixed> $rules
     */
    private function assertRule(
        array $rules,
        string $path,
        int $depth,
    ): void {
        if ($depth > self::MAX_SCHEMA_DEPTH) {
            throw new InvalidArgumentException(
                'Validation schema exceeds maximum nesting depth.',
            );
        }

        $type = $rules['type'] ?? null;

        if (
            ! in_array(
                $type,
                [
                    'string',
                    'integer',
                    'boolean',
                    'object',
                    'array',
                ],
                true,
            )
        ) {
            throw new InvalidArgumentException(
                'Unsupported validation field type.',
            );
        }

        /*
         * Validate new optional rules unconditionally.
         *
         * This must execute even when the corresponding input
         * field is missing, otherwise invalid schemas pass.
         */
        ExtendedRules::assertSchema(
            $rules,
            $type,
        );

        /*
         * Allowed rules depend strictly on field type.
         *
         * The default arm represents array, because the
         * supported-type guard already excludes other values.
         */
        $allowed = match ($type) {
            'string' => [
                'type',
                'required',
                'nullable',
                'notBlank',
                'minLength',
                'maxLength',
                'maxBytes',
                'enum',
            ],

            'integer' => [
                'type',
                'required',
                'nullable',
                'min',
                'max',
                'enum',
            ],

            'boolean' => [
                'type',
                'required',
                'nullable',
                'enum',
            ],

            'object' => [
                'type',
                'required',
                'nullable',
                'fields',
            ],

            default => [
                'type',
                'required',
                'nullable',
                'items',
                'minItems',
                'maxItems',
                'uniqueItems',
            ],
        };

        foreach ($rules as $name => $_setting) {
            if (! in_array($name, $allowed, true)) {
                throw new InvalidArgumentException(
                    'Unsupported validation rule.',
                );
            }
        }

        /*
         * Boolean schema flags must contain actual booleans.
         */
        foreach (
            ['required', 'nullable', 'notBlank'] as $flag
        ) {
            if (
                array_key_exists($flag, $rules)
                && ! is_bool($rules[$flag])
            ) {
                throw new InvalidArgumentException(
                    'Validation flags must be boolean.',
                );
            }
        }

        /*
         * Bounds must contain actual integers.
         */
        foreach (
            [
                'minLength',
                'maxLength',
                'min',
                'max',
                'minItems',
                'maxItems',
            ] as $bound
        ) {
            if (
                array_key_exists($bound, $rules)
                && ! is_int($rules[$bound])
            ) {
                throw new InvalidArgumentException(
                    'Validation bounds must be integers.',
                );
            }
        }

        /*
         * Validate nonnegative sizes and ordered bounds.
         */
        if (
            (
                isset($rules['minLength'])
                && $rules['minLength'] < 0
            )
            || (
                isset($rules['maxLength'])
                && $rules['maxLength'] < 0
            )
            || (
                isset($rules['minItems'])
                && $rules['minItems'] < 0
            )
            || (
                isset($rules['maxItems'])
                && $rules['maxItems'] < 0
            )
            || (
                isset(
                    $rules['minLength'],
                    $rules['maxLength'],
                )
                && $rules['minLength'] > $rules['maxLength']
            )
            || (
                isset(
                    $rules['min'],
                    $rules['max'],
                )
                && $rules['min'] > $rules['max']
            )
            || (
                isset(
                    $rules['minItems'],
                    $rules['maxItems'],
                )
                && $rules['minItems'] > $rules['maxItems']
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid validation bounds.',
            );
        }

        /*
         * OBJECT SCHEMA
         */
        if ($type === 'object') {
            if (
                ! isset($rules['fields'])
                || ! is_array($rules['fields'])
            ) {
                throw new InvalidArgumentException(
                    'Object validation requires a fields schema.',
                );
            }

            // Reserve space for nested unknown-field markers
            // such as profile._input in 422 responses.
            $this->assertPath(
                $path . '._input',
            );

            /** @var array<array-key, mixed> $fields */
            $fields = $rules['fields'];

            $this->assertFields(
                $fields,
                $path,
                $depth + 1,
            );
        }

        /*
         * ARRAY SCHEMA
         */
        if ($type === 'array') {
            if (
                ! isset($rules['items'])
                || ! is_array($rules['items'])
            ) {
                throw new InvalidArgumentException(
                    'Array validation requires item rules.',
                );
            }

            /** @var array<string, mixed> $items */
            $items = $rules['items'];

            // Reserve the maximum native integer index width.
            $itemPath = $path . '[' . PHP_INT_MAX . ']';

            $this->assertPath($itemPath);

            $this->assertRule(
                $items,
                $itemPath,
                $depth + 1,
            );
        }
    }

    /**
     * Limit schema-derived paths used in public validation errors.
     */
    private function assertPath(string $path): void
    {
        if (
            strlen($path) > self::MAX_ERROR_PATH_BYTES
        ) {
            throw new InvalidArgumentException(
                'Validation schema path is too long.',
            );
        }
    }
}
