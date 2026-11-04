<?php

declare(strict_types=1);

namespace Careminate\Http\Exception;

use Careminate\Http\Response;
use InvalidArgumentException;

/**
 * Builds minimal JSON error responses without exposing exception messages.
 */
final class ProblemResponseFactory
{
    public function create(int $status): Response
    {
        if ($status < 400 || $status > 599) {
            throw new InvalidArgumentException(
                'Problem response status must be between 400 and 599.',
            );
        }

        $title = match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            413 => 'Content Too Large',
            415 => 'Unsupported Media Type',
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
            default => $status >= 500
                ? 'Server Error'
                : 'Client Error',
        };

        return new Response(
            $status,
            [
                'Content-Type' => 'application/problem+json',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            json_encode(
                [
                    'type' => 'about:blank',
                    'title' => $title,
                    'status' => $status,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
        );
    }
}
