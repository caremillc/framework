<?php

declare(strict_types=1);

namespace Careminate\Http;

use InvalidArgumentException;

final readonly class Response
{
    /**
     * @var array<string, string>
     */
    public array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status = 200,
        array $headers = [],
        public string $body = '',
    ) {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException(
                'HTTP response status must be between 100 and 599.'
            );
        }

        if ($this->mustBeEmpty($status) && $body !== '') {
            throw new InvalidArgumentException(
                'This HTTP status must not contain a response body.'
            );
        }

        $this->headers = self::normalizeHeaders($headers);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function withStatus(int $status): self
    {
        return new self(
            $status,
            $this->headers,
            $this->body,
        );
    }

    public function withHeader(
        string $name,
        string $value,
    ): self {
        $headers = $this->headers;
        $headers[$name] = $value;

        return new self(
            $this->status,
            $headers,
            $this->body,
        );
    }

    public function withBody(string $body): self
    {
        return new self(
            $this->status,
            $this->headers,
            $body,
        );
    }

    private function mustBeEmpty(int $status): bool
    {
        return ($status >= 100 && $status < 200)
            || $status === 204
            || $status === 304;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (
                preg_match(
                    "/\A[!#$%&'*+.^_`|~0-9A-Za-z-]+\z/D",
                    $name
                ) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Invalid HTTP response header name.'
                );
            }

            if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException(
                    'Invalid HTTP response header value.'
                );
            }

            $normalized[strtolower($name)] = $value;
        }

        return $normalized;
    }
}
