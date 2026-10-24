<?php

declare (strict_types=1);

namespace Careminate\Http;

use InvalidArgumentException;

final readonly class Request
{
    public string $method;

    public string $target;

    /**
     * @var array<string, string>
     */
    public array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $method = 'GET',
        string $target = '/',
        array $headers = [],
        public string $body = '',
    ) {
        $this->method  = self::normalizeMethod($method);
        $this->target  = self::validateTarget($target);
        $this->headers = self::normalizeHeaders($headers);
    }

    public function path(): string
    {
        $position = strpos($this->target, '?');

        return $position === false
            ? $this->target
            : substr($this->target, 0, $position);
    }

    public function queryString(): string
    {
        $position = strpos($this->target, '?');

        return $position === false
            ? ''
            : substr($this->target, $position + 1);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function withMethod(string $method): self
    {
        return new self(
            $method,
            $this->target,
            $this->headers,
            $this->body,
        );
    }

    public function withTarget(string $target): self
    {
        return new self(
            $this->method,
            $target,
            $this->headers,
            $this->body,
        );
    }

    public function withHeader(
        string $name,
        string $value,
    ): self {
        $headers        = $this->headers;
        $headers[$name] = $value;

        return new self(
            $this->method,
            $this->target,
            $headers,
            $this->body,
        );
    }

    public function withBody(string $body): self
    {
        return new self(
            $this->method,
            $this->target,
            $this->headers,
            $body,
        );
    }

    private static function normalizeMethod(string $method): string
    {
        if (
            preg_match(
                "/\A[!#$%&'*+.^_`|~0-9A-Za-z-]+\z/D",
                $method
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Invalid HTTP method.'
            );
        }

        return strtoupper($method);
    }

    private static function validateTarget(string $target): string
    {
        if (
            $target === ''
            || $target[0] !== '/'
            || preg_match('/[\x00-\x20\x7F]/', $target) === 1
            || str_contains($target, '#')
        ) {
            throw new InvalidArgumentException(
                'Invalid HTTP request target.'
            );
        }

        return $target;
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
                    'Invalid HTTP header name.'
                );
            }

            if (
                preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            ) {
                throw new InvalidArgumentException(
                    'Invalid HTTP header value.'
                );
            }

            $normalized[strtolower($name)] = $value;
        }

        return $normalized;
    }
}
