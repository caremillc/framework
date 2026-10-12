<?php

declare(strict_types=1);

namespace Flint\Http;

use InvalidArgumentException;
use RuntimeException;

final class PhpRequestFactory
{
    public function __construct(
        private readonly ServerRequestFactory $serverFactory = new ServerRequestFactory(),
        private readonly RequestBodyReader $bodyReader = new RequestBodyReader(),
    ) {
    }

    public function capture(): Request
    {
        $server = $_SERVER;

        // Validate metadata before opening the request body.
        $metadata = $this->serverFactory->create($server);

        $contentType = $metadata->headers()->get('Content-Type')[0] ?? '';
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));

        if ($mediaType === 'multipart/form-data') {
            throw new InvalidArgumentException(
                'Multipart request capture is not supported.',
            );
        }

        $stream = @fopen('php://input', 'rb');

        if ($stream === false) {
            throw new RuntimeException(
                'The PHP request body stream could not be opened.',
            );
        }

        try {
            $body = $this->bodyReader->read($stream);
        } finally {
            fclose($stream);
        }

        return new Request(
            method: $metadata->method(),
            target: $metadata->target(),
            headers: $metadata->headers(),
            body: $body,
        );
    }
}
