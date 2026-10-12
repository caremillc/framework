<?php

declare(strict_types=1);

namespace Flint\Http;

use InvalidArgumentException;

final readonly class Request
{
    private Headers $headers;

    private string $path;

    private ?string $queryString;

    public function __construct(
        private string $method = 'GET',
        private string $target = '/',
        ?Headers $headers = null,
        private string $body = '',
    ) {
        if (
            preg_match(
                '/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/',
                $method,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Request methods must be non-empty HTTP tokens.',
            );
        }

        if (
            preg_match(
                '/\A\/[A-Za-z0-9._~!$&\'()*+,;=:@%\/?-]*\z/',
                $target,
            ) !== 1
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $target) !== 0
        ) {
            throw new InvalidArgumentException(
                'Request targets must be valid origin-form paths with an optional query.',
            );
        }

        $parts = explode('?', $target, 2);

        $this->path = $parts[0];
        $this->queryString = $parts[1] ?? null;
        $this->headers = $headers ?? new Headers();
    }

    public function method(): string
    {
        return $this->method;
    }

    public function target(): string
    {
        return $this->target;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function queryString(): ?string
    {
        return $this->queryString;
    }

    public function headers(): Headers
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }
}
