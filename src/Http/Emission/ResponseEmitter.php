<?php

declare(strict_types=1);

namespace Careminate\Http\Emission;

use Careminate\Http\Response;
use LogicException;

final class ResponseEmitter
{
    private bool $emitted = false;

    public function __construct(
        private readonly ResponseOutput $output,
    ) {
    }

    public function emit(Response $response): void
    {
        if ($this->emitted) {
            throw new LogicException(
                'HTTP response has already been emitted.'
            );
        }

        // Mark as used before output begins. An output failure
        // may leave a partially transmitted HTTP response.
        $this->emitted = true;

        $this->output->assertReady();

        $this->output->sendStatus($response->status);

        foreach ($response->headers as $name => $value) {
            $this->output->sendHeader($name, $value);
        }

        if ($response->body !== '') {
            $this->output->write($response->body);
        }
    }

    public function hasEmitted(): bool
    {
        return $this->emitted;
    }
}
