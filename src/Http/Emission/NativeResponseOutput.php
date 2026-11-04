<?php

declare(strict_types=1);

namespace Careminate\Http\Emission;

use RuntimeException;

final class NativeResponseOutput implements ResponseOutput
{
    public function assertReady(): void
    {
        if (headers_sent($file, $line)) {
            throw new RuntimeException(
                sprintf(
                    'HTTP headers have already been sent at %s:%d.',
                    $file,
                    $line,
                ),
            );
        }
    }

    public function sendStatus(int $status): void
    {
        $this->assertReady();

        http_response_code($status);
    }

    public function sendHeader(
        string $name,
        string $value,
    ): void {
        $this->assertReady();

        header(
            sprintf('%s: %s', $name, $value),
            true,
        );
    }

    public function write(string $body): void
    {
        echo $body;
    }
}
