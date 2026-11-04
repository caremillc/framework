<?php

declare(strict_types=1);

namespace Careminate\Http\Exception;

use Careminate\Http\Response;
use Careminate\Validation\Exception\ValidationException;

/**
 * Creates a safe HTTP 422 response with field-level validation errors.
 */
final class ValidationProblemResponseFactory
{
    public function create(
        ValidationException $exception,
    ): Response {
        $response = new ProblemResponseFactory()->create(422);

        return $response->withBody(
            json_encode(
                [
                    'type' => 'about:blank',
                    'title' => 'Unprocessable Content',
                    'status' => 422,
                    'errors' => $exception->errors(),
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
        );
    }
}
