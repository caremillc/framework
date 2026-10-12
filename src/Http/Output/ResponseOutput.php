<?php

declare(strict_types=1);

namespace Flint\Http\Output;

interface ResponseOutput
{
    /**
     * Verify output is available and initialize the response output.
     */
    public function begin(): void;

    public function writeHeader(
        string $name,
        string $value,
        bool $replace,
    ): void;

    public function writeStatus(int $status): void;

    public function writeBody(string $body): void;
}
