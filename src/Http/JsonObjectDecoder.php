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
        bool $bigIntegersAsStrings = true,
    ): stdClass {
        if ($maxDepth < 1 || $maxDepth > 512) {
            throw new InvalidArgumentException(
                'JSON depth must be between 1 and 512.',
            );
        }

        $flags = JSON_THROW_ON_ERROR;

        if ($bigIntegersAsStrings) {
            $flags |= JSON_BIGINT_AS_STRING;
        }

        try {
            $document = json_decode(
                $body,
                false,
                $maxDepth,
                $flags,
            );
        } catch (JsonException $exception) {
            throw new InvalidJsonException(
                'The request body contains invalid JSON.',
                previous: $exception,
            );
        }

        if (!$document instanceof stdClass) {
            throw new InvalidJsonException(
                'The JSON request body must contain an object.',
            );
        }

        return $document;
    }
}
