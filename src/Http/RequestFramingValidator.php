<?php

declare(strict_types=1);

namespace Flint\Http;

use InvalidArgumentException;

final class RequestFramingValidator
{
    public function validate(Request $request): void
    {
        $headers = $request->headers();

        if ($headers->has('Transfer-Encoding')) {
            throw new InvalidArgumentException(
                'Transfer-Encoding is not supported by this request capture implementation.',
            );
        }

        $lengths = $headers->get('Content-Length');

        if ($lengths === []) {
            return;
        }

        if (
            count($lengths) !== 1
            || preg_match('/\A[0-9]+\z/', $lengths[0]) !== 1
        ) {
            throw new InvalidArgumentException(
                'Request Content-Length must contain one decimal byte count.',
            );
        }

        $declared = ltrim($lengths[0], '0');

        if ($declared === '') {
            $declared = '0';
        }

        $actual = (string) strlen($request->body());

        if ($declared !== $actual) {
            throw new InvalidArgumentException(
                'Request Content-Length does not match the captured body.',
            );
        }
    }
}
