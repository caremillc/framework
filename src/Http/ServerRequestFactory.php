<?php

declare(strict_types=1);

namespace Careminate\Http;

use InvalidArgumentException;
use RuntimeException;

final class ServerRequestFactory
{
    private const int DEFAULT_MAX_BODY_BYTES = 1048576;

    public function __construct(
        private readonly int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
    ) {
        if ($this->maxBodyBytes < 0) {
            throw new InvalidArgumentException(
                'Maximum request body size cannot be negative.'
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
        if (strlen($body) > $this->maxBodyBytes) {
            throw new InvalidArgumentException(
                'HTTP request body exceeds the configured size limit.'
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

        return new Request(
            method: $method,
            target: $target,
            headers: $this->extractHeaders($server),
            body: $body,
        );
    }

    /**
     * @param array<string, mixed> $server
     */
    public function fromGlobals(array $server): Request
    {
        $stream = fopen('php://input', 'rb');

        if ($stream === false) {
            throw new RuntimeException(
                'Unable to open the HTTP request body stream.'
            );
        }

        try {
            $body = stream_get_contents(
                $stream,
                $this->maxBodyBytes + 1,
            );

            if ($body === false) {
                throw new RuntimeException(
                    'Unable to read the HTTP request body.'
                );
            }
        } finally {
            fclose($stream);
        }

        return $this->fromServer($server, $body);
    }

    /**
     * @param array<string, mixed> $server
     */
    private function requiredString(
        array $server,
        string $key,
    ): string {
        $value = $server[$key] ?? null;

        if (!is_string($value) || $value === '') {
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
    private function extractHeaders(array $server): array
    {
        $headers = [];
        $normalizedNames = [];

        foreach ($server as $key => $value) {
            if (
                !str_starts_with($key, 'HTTP_')
                && $key !== 'CONTENT_TYPE'
                && $key !== 'CONTENT_LENGTH'
            ) {
                continue;
            }

            if (!is_string($value)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'HTTP header source "%s" must be a string.',
                        $key,
                    ),
                );
            }

            $name = match ($key) {
                'CONTENT_TYPE' => 'Content-Type',
                'CONTENT_LENGTH' => 'Content-Length',
                default => str_replace(
                    '_',
                    '-',
                    substr($key, 5),
                ),
            };

            if ($name === '') {
                throw new InvalidArgumentException(
                    'HTTP header name cannot be empty.'
                );
            }

            $normalizedName = strtolower($name);

            if (isset($normalizedNames[$normalizedName])) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Duplicate HTTP header "%s".',
                        $name,
                    ),
                );
            }

            $normalizedNames[$normalizedName] = true;
            $headers[$name] = $value;
        }

        return $headers;
    }
}
