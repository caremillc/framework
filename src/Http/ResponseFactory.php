<?php

declare(strict_types=1);

namespace Flint\Http;

final class ResponseFactory
{
    public static function text(string $body, int $status = 200): Response
    {
        return new Response(
            status: $status,
            body: $body,
            headers: new Headers()->with(
                'Content-Type',
                'text/plain; charset=utf-8',
            ),
        );
    }

    public static function json(mixed $data, int $status = 200): Response
    {
        $body = json_encode(
            $data,
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
        );

        return new Response(
            status: $status,
            body: $body,
            headers: new Headers()->with(
                'Content-Type',
                'application/json',
            ),
        );
    }
}
