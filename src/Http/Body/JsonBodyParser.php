<?php

declare (strict_types=1);

namespace Careminate\Http\Body;

use Careminate\Http\Exception\HttpException;
use Careminate\Http\Request;
use InvalidArgumentException;
use JsonException;

final readonly class JsonBodyParser
{
    public function __construct(
        private int $maxBodyBytes = 1048576,
        private int $maxDepth = 64,
    ) {
        if ($maxBodyBytes < 0) {
            throw new InvalidArgumentException(
                'Maximum JSON body size must not be negative.',
            );
        }

        if ($maxDepth < 1 || $maxDepth > 512) {
            throw new InvalidArgumentException(
                'Maximum JSON nesting depth must be between 1 and 512.',
            );
        }
    }

    /**
     * Parse one JSON object into associative PHP arrays.
     *
     * @return array<array-key, mixed>
     */
    public function parseObject(Request $request): array
    {
        if (strlen($request->body) > $this->maxBodyBytes) {
            throw new HttpException(
                413,
                'Request body exceeds the JSON size limit.',
            );
        }

        $contentType = $request->header('Content-Type');

        if (
            $contentType === null
            || ! self::isJsonContentType($contentType)
        ) {
            throw new HttpException(
                415,
                'An application/json content type is required.',
            );
        }

        $body = ltrim($request->body, " \t\r\n");

        if ($body === '') {
            throw new HttpException(
                400,
                'JSON request body is empty.',
            );
        }

        try {
            $value = json_decode(
                $request->body,
                true,
                max(1, $this->maxDepth),
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            );
        } catch (JsonException $exception) {
            throw new HttpException(
                400,
                'Malformed JSON request body.',
                $exception,
            );
        }

        // Associative decoding maps both JSON objects and
        // arrays to PHP arrays. Check the root JSON token.
        if (
            $body[0] !== '{'
            || ! is_array($value)
        ) {
            throw new HttpException(
                400,
                'JSON request body must contain an object.',
            );
        }

        // Run only after JSON decoding and root validation succeed.
        // Unlike associative decoding, this scan detects duplicate
        // property names before they are silently overwritten.
        JsonDuplicateKeyGuard::assertUnique(
            $request->body,
        );

        return $value;
    }

    private static function isJsonContentType(
        string $contentType,
    ): bool {
        $parts = explode(';', $contentType);

        $mediaType = strtolower(
            trim(array_shift($parts)),
        );

        if (
            $mediaType !== 'application/json'
            && preg_match(
                '~\Aapplication/[a-z0-9][a-z0-9!#$&^_.+-]*\+json\z~D',
                $mediaType,
            ) !== 1
        ) {
            return false;
        }

        if (count($parts) > 1) {
            return false;
        }

        return $parts === []
        || preg_match(
            '/\A\s*charset\s*=\s*(?:"utf-8"|utf-8)\s*\z/iD',
            $parts[0],
        ) === 1;
    }
}
