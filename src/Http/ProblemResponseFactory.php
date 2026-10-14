<?php

declare(strict_types=1);

namespace Flint\Http;

use InvalidArgumentException;

final class ProblemResponseFactory
{
    public static function create(
        int $status,
        string $title,
        ?string $detail = null,
    ): Response {
        if ($status < 400 || $status > 599) {
            throw new InvalidArgumentException(
                'Problem responses must use a status between 400 and 599.',
            );
        }

        if (trim($title) === '') {
            throw new InvalidArgumentException(
                'Problem responses require a non-blank title.',
            );
        }

        $document = [
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
        ];

        if ($detail !== null) {
            $document['detail'] = $detail;
        }

        $response = ResponseFactory::json($document, $status);

        return $response->withHeaders(
            $response->headers()->with(
                'Content-Type',
                'application/problem+json',
            ),
        );
    }
}
