<?php

declare(strict_types=1);

namespace Flint\Http;

use Flint\Http\Exception\InvalidJsonException;
use InvalidArgumentException;
use JsonException;
use stdClass;

final class JsonObjectDecoder
{
    public static function decode(
        string $body,
        int $maxDepth = 64,
    ): stdClass {
        if ($maxDepth < 1 || $maxDepth > 512) {
            throw new InvalidArgumentException(
                'JSON decoding depth must be between 1 and 512.',
            );
        }

        try {
            $decoded = json_decode(
                $body,
                associative: false,
                depth: $maxDepth,
                flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            );
        } catch (JsonException $exception) {
            throw new InvalidJsonException(
                'The request body contains invalid JSON.',
                previous: $exception,
            );
        }

        if (! $decoded instanceof stdClass) {
            throw new InvalidJsonException(
                'The JSON request body must contain an object.',
            );
        }

        return $decoded;
    }
}
