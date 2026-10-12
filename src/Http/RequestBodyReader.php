<?php

declare(strict_types=1);

namespace Flint\Http;

use Flint\Http\Exception\RequestBodyTooLargeException;
use InvalidArgumentException;
use RuntimeException;

final readonly class RequestBodyReader
{
    public function __construct(
        private int $maxBytes = 1_048_576,
    ) {
        if ($maxBytes < 0) {
            throw new InvalidArgumentException(
                'The request body limit must not be negative.',
            );
        }
    }

    public function read(mixed $stream): string
    {
        if (
            !is_resource($stream)
            || get_resource_type($stream) !== 'stream'
        ) {
            throw new InvalidArgumentException(
                'The request body source must be an open stream.',
            );
        }

        $body = '';

        while (!feof($stream)) {
            $remaining = $this->maxBytes - strlen($body);
            $readLength = max(1, min(8192, $remaining));

            $chunk = @fread($stream, $readLength);

            if ($chunk === false) {
                throw new RuntimeException(
                    'The request body could not be read.',
                );
            }

            if ($chunk === '') {
                if (feof($stream)) {
                    break;
                }

                throw new RuntimeException(
                    'The request body stream stopped making progress.',
                );
            }

            if (strlen($chunk) > $remaining) {
                throw new RequestBodyTooLargeException(
                    'The request body exceeds the configured byte limit.',
                );
            }

            $body .= $chunk;
        }

        return $body;
    }
}
