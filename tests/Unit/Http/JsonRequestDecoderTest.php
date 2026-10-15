<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Flint\Http\Exception\InvalidJsonException;
use Flint\Http\Exception\UnsupportedJsonMediaTypeException;
use Flint\Http\Headers;
use Flint\Http\JsonRequestDecoder;
use Flint\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonRequestDecoderTest extends TestCase
{
    #[DataProvider('supportedContentTypes')]
    public function testSupportedMediaTypesDecodeObjects(
        string $contentType,
    ): void {
        $request = new Request(
            method: 'POST',
            target: '/example',
            headers: (new Headers())->with(
                'Content-Type',
                $contentType,
            ),
            body: '{"name":"Ada","enabled":false,"count":0}',
        );

        $document = JsonRequestDecoder::decode($request);

        self::assertSame('Ada', $document->name);
        self::assertFalse($document->enabled);
        self::assertSame(0, $document->count);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function supportedContentTypes(): iterable
    {
        yield 'standard' => ['application/json'];
        yield 'mixed case' => ['Application/JSON'];
        yield 'parameters' => ['application/json; charset=utf-8'];
    }

    #[DataProvider('unsupportedHeaders')]
    public function testUnsupportedHeadersAreRejectedBeforeBodyDecoding(
        Headers $headers,
    ): void {
        $request = new Request(
            method: 'POST',
            target: '/example',
            headers: $headers,
            body: '{"broken":',
        );

        $this->expectException(
            UnsupportedJsonMediaTypeException::class,
        );

        $this->expectExceptionMessage(
            'Use application/json without Content-Encoding.',
        );

        JsonRequestDecoder::decode($request);
    }

    /**
     * @return iterable<string, array{Headers}>
     */
    public static function unsupportedHeaders(): iterable
    {
        yield 'missing content type' => [
            new Headers(),
        ];

        yield 'plain text' => [
            (new Headers())->with('Content-Type', 'text/plain'),
        ];

        yield 'duplicate content types' => [
            (new Headers())->with(
                'Content-Type',
                'application/json',
                'application/json',
            ),
        ];

        yield 'gzip encoding' => [
            (new Headers())
                ->with('Content-Type', 'application/json')
                ->with('Content-Encoding', 'gzip'),
        ];

        yield 'identity encoding' => [
            (new Headers())
                ->with('Content-Type', 'application/json')
                ->with('Content-Encoding', 'identity'),
        ];

        yield 'unaccepted JSON subtype' => [
            (new Headers())->with(
                'Content-Type',
                'application/problem+json',
            ),
        ];
    }

    #[DataProvider('invalidBodies')]
    public function testInvalidDocumentsRetainTheJsonExceptionType(
        string $body,
    ): void {
        $this->expectException(InvalidJsonException::class);

        JsonRequestDecoder::decode(self::request($body));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'empty' => [''];
        yield 'malformed' => ['{"name":'];
        yield 'array root' => ['[]'];
        yield 'null root' => ['null'];
        yield 'invalid UTF-8' => ["{\"name\":\"\xFF\"}"];
    }

    public function testLargeIntegersBecomeStringsByDefault(): void
    {
        $document = JsonRequestDecoder::decode(
            self::request(
                '{"value":123456789012345678901234567890}',
            ),
        );

        self::assertSame(
            '123456789012345678901234567890',
            $document->value,
        );
    }

    public function testLargeIntegerStringConversionCanBeDisabled(): void
    {
        $document = JsonRequestDecoder::decode(
            self::request(
                '{"value":123456789012345678901234567890}',
            ),
            bigIntegersAsStrings: false,
        );

        self::assertIsFloat($document->value);
    }

    #[DataProvider('invalidDepths')]
    public function testInvalidDepthRemainsAProgrammingError(
        int $depth,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'JSON depth must be between 1 and 512.',
        );

        JsonRequestDecoder::decode(
            self::request('{}'),
            maxDepth: $depth,
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidDepths(): iterable
    {
        yield 'zero' => [0];
        yield 'above maximum' => [513];
    }

    public function testRepeatedDecodingReturnsIndependentDocuments(): void
    {
        $request = self::request('{"name":"Ada"}');

        $first = JsonRequestDecoder::decode($request);
        $first->name = 'Changed';

        $second = JsonRequestDecoder::decode($request);

        self::assertNotSame($first, $second);
        self::assertSame('Ada', $second->name);
        self::assertSame('{"name":"Ada"}', $request->body());
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
