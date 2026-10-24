<?php

declare(strict_types=1);

namespace Careminate\Http\Emission;

interface ResponseOutput
{
    public function assertReady(): void;

    public function sendStatus(int $status): void;

    public function sendHeader(
        string $name,
        string $value,
    ): void;

    public function write(string $body): void;
}
