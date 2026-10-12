<?php

declare (strict_types=1);

namespace Flint\Http\Output;

use RuntimeException;

final class PhpResponseOutput implements ResponseOutput
{
    private bool $begun = false;

    public function begin(): void
    {
        if ($this->begun) {
            throw new RuntimeException(
                'This PHP response output has already begun.',
            );
        }

        if (headers_sent()) {
            throw new RuntimeException(
                'Cannot emit a response after headers have been sent.',
            );
        }

        /** @var list<array{buffer_used: int, name: string}> $buffers */
        $buffers = ob_get_status(true);

        foreach ($buffers as $buffer) {
            if ($buffer['buffer_used'] > 0) {
                throw new RuntimeException(
                    'Cannot emit a response while buffered output is pending.',
                );
            }

            if ($buffer['name'] !== 'default output handler') {
                throw new RuntimeException(
                    'Custom output handlers are not supported during response emission.',
                );
            }
        }

        $compression = strtolower(
            (string) ini_get('zlib.output_compression'),
        );

        if (! in_array($compression, ['', '0', 'off'], true)) {
            throw new RuntimeException(
                'PHP output compression must be disabled during response emission.',
            );
        }

        $this->begun = true;

        header_remove();
    }

    public function writeHeader(
        string $name,
        string $value,
        bool $replace,
    ): void {
        header($name . ': ' . $value, $replace);
    }

    public function writeStatus(int $status): void
    {
        http_response_code($status);
    }

    public function writeBody(string $body): void
    {
        echo $body;
    }
}
