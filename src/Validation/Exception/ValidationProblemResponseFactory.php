<?php

declare(strict_types=1);

namespace Careminate\Validation\Exception;

use Careminate\Http\Exception\ProblemResponseFactory;
use Careminate\Http\Response;

/**
 * Creates a safe HTTP 422 problem response with field-level errors.
 */
final class ValidationProblemResponseFactory
{
    public function create(
        ValidationException $exception,
    ): Response {
        $base = new ProblemResponseFactory()->create(422);

        return $base->withBody(
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
