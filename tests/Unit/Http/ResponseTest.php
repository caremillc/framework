<?php

declare (strict_types=1);

namespace Careminate\Tests\Unit\Http;

use Careminate\Http\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testDefaults(): void
    {
        $response = new Response();

        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
        self::assertSame([], $response->headers);
    }

    public function testCustomResponse(): void
    {
        $response = new Response(
            201,
            ['Content-Type' => 'application/json'],
            '{"created":true}'
        );

        self::assertSame(201, $response->status);
        self::assertSame(
            'application/json',
            $response->header('content-type')
        );
        self::assertSame(
            '{"created":true}',
            $response->body
        );
    }

    public function testWithMethodsPreserveOriginal(): void
    {
        $original = new Response();

        $modified = $original
            ->withStatus(404)
            ->withHeader('X-Test', 'value')
            ->withBody('Not found');

        self::assertSame(200, $original->status);
        self::assertSame('', $original->body);

        self::assertSame(404, $modified->status);
        self::assertSame(
            'value',
            $modified->header('x-test')
        );
        self::assertSame('Not found', $modified->body);
    }

    public function testInvalidStatusIsRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        new Response(99);
    }

    public function testHeaderInjectionIsRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        new Response(
            headers: [
                'Location' => "/safe\r\nX-Injected: yes",
            ]
        );
    }

    public function testHeaderNamesAreCaseInsensitive(): void
    {
        $response = new Response(
            headers: ['X-Request-Id' => 'abc']
        );

        self::assertSame(
            'abc',
            $response->header('x-request-id')
        );
    }
}
