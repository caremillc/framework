<?php

declare(strict_types=1);

namespace Careminate\Http\Body;

use Careminate\Http\Request;
use Careminate\Validation\Exception\ValidationException;
use Careminate\Validation\Validator;

/**
 * Composes strict JSON parsing with schema validation.
 *
 * This class owns no request state. It does not catch parser errors,
 * so 400/413/415 remain distinct from field-validation 422 errors.
 */
final readonly class ValidatedJsonBodyParser
{
    public function __construct(
        private JsonBodyParser $json,
        private Validator $validator,
    ) {
    }

    /**
     * @param array<string, array<string, mixed>> $schema
     *
     * @return array<string, mixed>
     */
    public function parse(
        Request $request,
        array $schema,
        bool $rejectUnknown = true,
    ): array {
        $decoded = $this->json->parseObject($request);

        $result = $this->validator->validate(
            $decoded,
            $schema,
            $rejectUnknown,
        );

        if (! $result->isValid()) {
            throw new ValidationException($result);
        }

        return $result->validated();
    }
}
