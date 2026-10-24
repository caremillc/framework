<?php

declare(strict_types=1);

namespace Careminate\Http\Emission;

use LogicException;

final class BufferedResponseOutput implements ResponseOutput
{
    private ?int $status = null;

    /** @var array<string, string> */
    private array $headers = [];

    private string $body = '';

    public function assertReady(): void
    {
        if ($this->status !== null) {
            throw new LogicException(
                'Response output has already started.'
            );
        }
    }

    public function sendStatus(int $status): void
    {
        if ($this->status !== null) {
            throw new LogicException(
                'Response status has already been emitted.'
            );
        }

        $this->status = $status;
    }

    public function sendHeader(
        string $name,
        string $value,
    ): void {
        if ($this->status === null) {
            throw new LogicException(
                'Response status must be emitted before headers.'
            );
        }

        if ($this->body !== '') {
            throw new LogicException(
                'Cannot emit headers after response body output.'
            );
        }

        $this->headers[$name] = $value;
    }

    public function write(string $body): void
    {
        if ($this->status === null) {
            throw new LogicException(
                'Response status must be emitted before the body.'
            );
        }

        $this->body .= $body;
    }

    public function status(): ?int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }
}
