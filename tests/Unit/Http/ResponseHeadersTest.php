<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Flint\Http\Headers;
use Flint\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseHeadersTest extends TestCase
{
    public function testHeaderReplacementPreservesOriginalResponse(): void
    {
        $original = new Response(status: 201, body: 'Created');
        $headers = new Headers()->with('Content-Type', 'text/plain');

        $updated = $original->withHeaders($headers);

        self::assertNotSame($original, $updated);
        self::assertSame([], $original->headers()->all());
        self::assertSame($headers, $updated->headers());
        self::assertSame(201, $updated->status());
        self::assertSame('Created', $updated->body());
    }

    public function testBodyAndStatusUpdatesPreserveHeaders(): void
    {
        $headers = new Headers()->with('X-Request-Id', 'example');

        $original = new Response(
            status: 200,
            body: 'Original',
            headers: $headers,
        );

        $updated = $original
            ->withBody('Updated')
            ->withStatus(202);

        self::assertSame($headers, $updated->headers());
        self::assertSame('Original', $original->body());
        self::assertSame(200, $original->status());
        self::assertSame('Updated', $updated->body());
        self::assertSame(202, $updated->status());
    }
}
