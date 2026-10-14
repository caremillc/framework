<?php

declare(strict_types=1);

namespace Flint\Http;

use InvalidArgumentException;

final class ProblemResponseFactory
{
    /**
     * @param array<array-key, mixed> $extensions
     */
    public static function create(
        int $status,
        string $title,
        ?string $detail = null,
        array $extensions = [],
    ): Response {
        if ($status < 400 || $status > 599) {
            throw new InvalidArgumentException(
                'Problem responses require a status between 400 and 599.',
            );
        }

        if (trim($title) === '') {
            throw new InvalidArgumentException(
                'Problem responses require a non-empty title.',
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

        foreach ($extensions as $name => $value) {
            if (!is_string($name) || $name === '') {
                throw new InvalidArgumentException(
                    'Problem extension names must be non-empty strings.',
                );
            }

            if (
                in_array(
                    $name,
                    ['type', 'title', 'status', 'detail', 'instance'],
                    true,
                )
            ) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Problem extension "%s" conflicts with a standard member.',
                        $name,
                    ),
                );
            }

            $document[$name] = $value;
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
