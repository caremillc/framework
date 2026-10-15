<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Flint\Http\Exception\JsonBodyTooLargeException;
use Flint\Http\Exception\UnsupportedJsonMediaTypeException;
use Flint\Http\Headers;
use Flint\Http\JsonRequestDecoder;
use Flint\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class JsonRequestBodyLimitTest extends TestCase
{
    public function testBodyAtExactLimitIsAccepted(): void
    {
        $body = '{"name":"Ada"}' . str_repeat(' ', 32);

        $document = JsonRequestDecoder::decode(
            self::request($body),
            maxBytes: strlen($body),
        );

        self::assertSame('Ada', $document->name);
    }

    public function testOneByteAboveLimitIsRejected(): void
    {
        $body = '{"name":"Ada"}';

        $this->expectException(JsonBodyTooLargeException::class);

        JsonRequestDecoder::decode(
            self::request($body),
            maxBytes: strlen($body) - 1,
        );
    }

    public function testUtf8LimitCountsBytes(): void
    {
        $body = "{\"name\":\"\xC3\xA9\"}";

        $document = JsonRequestDecoder::decode(
            self::request($body),
            maxBytes: strlen($body),
        );

        self::assertSame("\xC3\xA9", $document->name);

        $this->expectException(JsonBodyTooLargeException::class);

        JsonRequestDecoder::decode(
            self::request($body),
            maxBytes: strlen($body) - 1,
        );
    }

    public function testOversizedMalformedBodyIsRejectedBeforeParsing(): void
    {
        $this->expectException(JsonBodyTooLargeException::class);

        JsonRequestDecoder::decode(
            self::request(str_repeat('{', 20)),
            maxBytes: 10,
        );
    }

    public function testUnsupportedMediaIsRejectedBeforeSizeChecking(): void
    {
        $request = new Request(
            method: 'POST',
            target: '/example',
            headers: (new Headers())->with(
                'Content-Type',
                'text/plain',
            ),
            body: str_repeat('x', 20),
        );

        $this->expectException(
            UnsupportedJsonMediaTypeException::class,
        );

        JsonRequestDecoder::decode($request, maxBytes: 10);
    }

    public function testNegativeLimitIsAProgrammingError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The JSON body byte limit must be non-negative.',
        );

        JsonRequestDecoder::decode(
            self::request('{}'),
            maxBytes: -1,
        );
    }

    public function testZeroLimitRejectsNonEmptyBody(): void
    {
        $this->expectException(JsonBodyTooLargeException::class);

        JsonRequestDecoder::decode(
            self::request('{}'),
            maxBytes: 0,
        );
    }

    public function testOmittedLimitDoesNotImposeAnEndpointLimit(): void
    {
        $body = '{"name":"Ada"}' . str_repeat(' ', 5000);

        $document = JsonRequestDecoder::decode(
            self::request($body),
        );

        self::assertSame('Ada', $document->name);
    }

    private static function request(string $body): Request
    {
        return new Request(
            method: 'POST',
            target: '/example',
            headers: (new Headers())->with(
                'Content-Type',
                'application/json',
            ),
            body: $body,
        );
    }
}
