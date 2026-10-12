<?php

declare(strict_types=1);

namespace Flint\Http;

use InvalidArgumentException;

final readonly class Response
{
    private Headers $headers;

    public function __construct(
        private int $status = 200,
        private string $body = '',
        ?Headers $headers = null,
    ) {
        if ($status < 200 || $status > 599) {
            throw new InvalidArgumentException(
                'Final response status must be between 200 and 599.',
            );
        }

        if (
            $body !== ''
            && in_array($status, [204, 205, 304], true)
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Response status %d requires an empty body.',
                    $status,
                ),
            );
        }

        $this->headers = $headers ?? new Headers();
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function headers(): Headers
    {
        return $this->headers;
    }

    public function withStatus(int $status): self
    {
        return new self(
            status: $status,
            body: $this->body,
            headers: $this->headers,
        );
    }

    public function withBody(string $body): self
    {
        return new self(
            status: $this->status,
            body: $body,
            headers: $this->headers,
        );
    }

    public function withHeaders(Headers $headers): self
    {
        return new self(
            status: $this->status,
            body: $this->body,
            headers: $headers,
        );
    }
}
