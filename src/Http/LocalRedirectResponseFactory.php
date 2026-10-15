<?php

declare(strict_types=1);

namespace Flint\Http;

use InvalidArgumentException;

final class LocalRedirectResponseFactory
{
    public static function create(
        string $target,
        int $status = 303,
    ): Response {
        if (! in_array($status, [301, 302, 303, 307, 308], true)) {
            throw new InvalidArgumentException(
                'Redirect status must be 301, 302, 303, 307, or 308.',
            );
        }

        if (
            ! str_starts_with($target, '/')
            || str_starts_with($target, '//')
            || str_contains($target, '\\')
        ) {
            throw new InvalidArgumentException(
                'Local redirect targets must start with a single slash and contain no backslashes.',
            );
        }

        // Reuse origin-form validation, including percent escapes,
        // forbidden characters, and fragment rejection.
        new Request(target: $target);

        return new Response(
            status: $status,
            body: '',
            headers: (new Headers())->with('Location', $target),
        );
    }
}
