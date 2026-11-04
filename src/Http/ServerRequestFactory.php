<?php

declare (strict_types=1);

namespace Careminate\Http;

use Careminate\Http\Exception\RequestBodyTooLargeException;
use InvalidArgumentException;
use RuntimeException;

final class ServerRequestFactory
{
    private const int DEFAULT_MAX_BODY_BYTES = 1048576;

    public function __construct(
        private readonly int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
    ) {
        if ($maxBodyBytes < 0) {
            throw new InvalidArgumentException(
                'Maximum request body size cannot be negative.',
            );
        }
    }

    /**
     * @param array<string, mixed> $server
     */
    public function fromServer(
        array $server,
        string $body = '',
    ): Request {
        $actualBytes = strlen($body);

        // if ($actualBytes > $this->maxBodyBytes) {
        //     throw new InvalidArgumentException(
        //         'HTTP request body exceeds the configured size limit.',
        //     );
        // }

        if ($actualBytes > $this->maxBodyBytes) {
            throw new RequestBodyTooLargeException(
                'HTTP request body exceeds the configured size limit.',
            );
        }
        $method = $this->requiredString(
            $server,
            'REQUEST_METHOD',
        );

        $target = $this->requiredString(
            $server,
            'REQUEST_URI',
        );

        $headers = $this->extractHeaders($server);

        $declaredBytes = $this->declaredContentLength(
            $headers,
        );

        if (
            $declaredBytes !== null
            && $declaredBytes !== $actualBytes
        ) {
            throw new InvalidArgumentException(
                'HTTP Content-Length does not match the received body size.',
            );
        }

        return new Request(
            method: $method,
            target: $target,
            headers: $headers,
            body: $body,
        );
    }

    /**
     * @param array<string, mixed> $server
     */
    public function fromGlobals(array $server): Request
    {
        // Reject oversized declared bodies and ambiguous
        // framing before reading php://input.
        $this->declaredContentLength(
            $this->extractHeaders($server),
        );

        $stream = fopen('php://input', 'rb');

        if ($stream === false) {
            throw new RuntimeException(
                'Unable to open the HTTP request body stream.',
            );
        }

        try {
            // Avoid overflow if configured with PHP_INT_MAX.
            $limit = $this->maxBodyBytes === PHP_INT_MAX
                ? PHP_INT_MAX
                : $this->maxBodyBytes + 1;

            $body = stream_get_contents(
                $stream,
                $limit,
            );

            if ($body === false) {
                throw new RuntimeException(
                    'Unable to read the HTTP request body.',
                );
            }
        } finally {
            fclose($stream);
        }

        return $this->fromServer(
            $server,
            $body,
        );
    }

    /**
     * Parse a normalized Content-Length without unsafe
     * integer conversion.
     *
     * @param array<string, string> $headers
     */
    private function declaredContentLength(
        array $headers,
    ): ?int {
        if (
            isset($headers['content-length'])
            && isset($headers['transfer-encoding'])
        ) {
            throw new InvalidArgumentException(
                'Conflicting HTTP request body framing headers.',
            );
        }

        $value = $headers['content-length'] ?? null;

        if ($value === null) {
            return null;
        }

        if (
            preg_match(
                '/\A[0-9]+\z/D',
                $value,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'HTTP Content-Length must be a nonnegative decimal integer.',
            );
        }

        // Normalize decimal leading zeros without converting
        // an untrusted value directly to an integer.
        $decimal = ltrim($value, '0');

        $decimal = $decimal === ''
            ? '0'
            : $decimal;

        $max = (string) $this->maxBodyBytes;

        // Decimal string comparison avoids overflow even for
        // an attacker-supplied integer containing thousands
        // of digits.
        if (
            strlen($decimal) > strlen($max)
            || (
                strlen($decimal) === strlen($max)
                && strcmp($decimal, $max) > 0
            )
        ) {
            throw new RequestBodyTooLargeException(
                'HTTP request body exceeds the configured size limit.',
            );
        }
        // Safe: the value has already been proved to be at
        // most $maxBodyBytes, which is a PHP integer.
        return (int) $decimal;
    }

    /**
     * @param array<string, mixed> $server
     */
    private function requiredString(
        array $server,
        string $key,
    ): string {
        $value = $server[$key] ?? null;

        if (
            ! is_string($value)
            || $value === ''
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'HTTP server variable "%s" must be a nonempty string.',
                    $key,
                ),
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $server
     *
     * @return array<string, string>
     */
    private function extractHeaders(
        array $server,
    ): array {
        $headers = [];

        /** @var array<string, string> $sources */
        $sources = [];

        foreach ($server as $key => $value) {
            if (
                ! str_starts_with($key, 'HTTP_')
                && $key !== 'CONTENT_TYPE'
                && $key !== 'CONTENT_LENGTH'
            ) {
                continue;
            }

            if (! is_string($value)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'HTTP header source "%s" must be a string.',
                        $key,
                    ),
                );
            }

            $name = match ($key) {
                'CONTENT_TYPE'   => 'Content-Type',
                'CONTENT_LENGTH' => 'Content-Length',
                default          => str_replace(
                    '_',
                    '-',
                    substr($key, 5),
                ),
            };

            if ($name === '') {
                throw new InvalidArgumentException(
                    'HTTP header name cannot be empty.',
                );
            }

            $normalizedName = strtolower($name);

            if (isset($sources[$normalizedName])) {
                $previousSource = $sources[$normalizedName];

                $isContentTypeAlias = (
                    $key === 'CONTENT_TYPE'
                    && $previousSource === 'HTTP_CONTENT_TYPE'
                ) || (
                    $key === 'HTTP_CONTENT_TYPE'
                    && $previousSource === 'CONTENT_TYPE'
                );

                $isContentLengthAlias = (
                    $key === 'CONTENT_LENGTH'
                    && $previousSource === 'HTTP_CONTENT_LENGTH'
                ) || (
                    $key === 'HTTP_CONTENT_LENGTH'
                    && $previousSource === 'CONTENT_LENGTH'
                );

                // Only two known aliases with identical raw
                // values are allowed to coexist.
                if (
                    ($isContentTypeAlias || $isContentLengthAlias)
                    && $headers[$normalizedName] === $value
                ) {
                    continue;
                }

                throw new InvalidArgumentException(
                    sprintf(
                        'Duplicate HTTP header "%s".',
                        $name,
                    ),
                );
            }

            $sources[$normalizedName] = $key;
            $headers[$normalizedName] = $value;
        }

        return $headers;
    }
}
