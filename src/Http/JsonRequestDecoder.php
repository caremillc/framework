<?php

declare(strict_types=1);

namespace Flint\Http;

use Flint\Http\Exception\JsonBodyTooLargeException;
use Flint\Http\Exception\UnsupportedJsonMediaTypeException;
use InvalidArgumentException;
use stdClass;

final class JsonRequestDecoder
{
    public static function decode(
        Request $request,
        int $maxDepth = 64,
        bool $bigIntegersAsStrings = true,
        ?int $maxBytes = null,
    ): stdClass {
        if ($maxBytes !== null && $maxBytes < 0) {
            throw new InvalidArgumentException(
                'The JSON body byte limit must be non-negative.',
            );
        }

        $contentTypes = $request->headers()->get('Content-Type');

        if (
            count($contentTypes) !== 1
            || $request->headers()->has('Content-Encoding')
        ) {
            throw new UnsupportedJsonMediaTypeException(
                'Use application/json without Content-Encoding.',
            );
        }

        $mediaType = strtolower(
            trim(explode(';', $contentTypes[0], 2)[0]),
        );

        if ($mediaType !== 'application/json') {
            throw new UnsupportedJsonMediaTypeException(
                'Use application/json without Content-Encoding.',
            );
        }

        if (
            $maxBytes !== null
            && strlen($request->body()) > $maxBytes
        ) {
            throw new JsonBodyTooLargeException(
                'The JSON request body exceeds the configured byte limit.',
            );
        }

        return JsonObjectDecoder::decode(
            $request->body(),
            maxDepth: $maxDepth,
            bigIntegersAsStrings: $bigIntegersAsStrings,
        );
    }
}
