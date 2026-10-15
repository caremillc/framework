<?php

declare(strict_types=1);

namespace Flint\Http;

use Flint\Http\Exception\UnsupportedJsonMediaTypeException;
use stdClass;

final class JsonRequestDecoder
{
    public static function decode(
        Request $request,
        int $maxDepth = 64,
        bool $bigIntegersAsStrings = true,
    ): stdClass {
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

        return JsonObjectDecoder::decode(
            $request->body(),
            maxDepth: $maxDepth,
            bigIntegersAsStrings: $bigIntegersAsStrings,
        );
    }
}
