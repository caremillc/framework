<?php

declare(strict_types=1);

namespace Flint\Http;

use InvalidArgumentException;

final readonly class Response
{
    public function __construct(
        private int $status = 200,
        private string $body = '',
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
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function withStatus(int $status): self
    {
        return new self(
            status: $status,
            body: $this->body,
        );
    }

    public function withBody(string $body): self
    {
        return new self(
            status: $this->status,
            body: $body,
        );
    }
}
