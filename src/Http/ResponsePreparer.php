<?php

declare(strict_types=1);

namespace Flint\Http;

use InvalidArgumentException;

final class ResponsePreparer
{
    public function prepare(Response $response): Response
    {
        $headers = $response->headers();

        if ($headers->has('Transfer-Encoding')) {
            throw new InvalidArgumentException(
                'Transfer-Encoding is not supported for buffered responses.',
            );
        }

        if (in_array($response->status(), [204, 304], true)) {
            $headers = $headers->without('Content-Length');
        } else {
            $headers = $headers->with(
                'Content-Length',
                (string) strlen($response->body()),
            );
        }

        return $response->withHeaders($headers);
    }
}
