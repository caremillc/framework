<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Flint\Http\Headers;
use Flint\Http\Response;
use Flint\Http\ResponsePreparer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponsePreparerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bodies(): iterable
    {
        yield 'empty' => ['', '0'];
        yield 'zero string' => ['0', '1'];
        yield 'multibyte text' => ["Caf\xC3\xA9", '5'];
        yield 'binary bytes' => ["\0\xFF\r\n", '4'];
    }

    #[DataProvider('bodies')]
    public function testLengthUsesBodyBytes(
        string $body,
        string $expectedLength,
    ): void {
        $response = new Response(body: $body);

        $prepared = new ResponsePreparer()->prepare($response);

        self::assertSame(
            [$expectedLength],
            $prepared->headers()->get('Content-Length'),
        );
        self::assertSame($body, $prepared->body());
    }

    public function testReplacesDeclaredLengthsWithoutChangingOriginal(): void
    {
        $headers = new Headers()->with('Content-Length', '999', '123');

        $original = new Response(
            status: 201,
            body: 'Hello',
            headers: $headers,
        );

        $prepared = new ResponsePreparer()->prepare($original);

        self::assertNotSame($original, $prepared);
        self::assertSame(
            ['999', '123'],
            $original->headers()->get('Content-Length'),
        );
        self::assertSame(
            ['5'],
            $prepared->headers()->get('Content-Length'),
        );
        self::assertSame(201, $prepared->status());
        self::assertSame('Hello', $prepared->body());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function statusesWithoutLength(): iterable
    {
        yield 'no content' => [204];
        yield 'not modified' => [304];
    }

    #[DataProvider('statusesWithoutLength')]
    public function testOmitsLengthWhenRequiredByPreparationPolicy(
        int $status,
    ): void {
        $response = new Response(
            status: $status,
            headers: new Headers()->with('Content-Length', '999'),
        );

        $prepared = new ResponsePreparer()->prepare($response);

        self::assertFalse($prepared->headers()->has('Content-Length'));
        self::assertSame($status, $prepared->status());
        self::assertSame('', $prepared->body());
    }

    public function testResetContentHasZeroLength(): void
    {
        $response = new Response(status: 205);

        $prepared = new ResponsePreparer()->prepare($response);

        self::assertSame(
            ['0'],
            $prepared->headers()->get('Content-Length'),
        );
        self::assertSame('', $prepared->body());
    }

    public function testTransferEncodingIsRejected(): void
    {
        $response = new Response(
            body: 'Hello',
            headers: new Headers()->with('Transfer-Encoding', 'chunked'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Transfer-Encoding is not supported for buffered responses.',
        );

        new ResponsePreparer()->prepare($response);
    }

    public function testPreservesOtherHeadersAndRepeatedValues(): void
    {
        $headers = new Headers()
            ->with('Content-Type', 'text/plain')
            ->with('Set-Cookie', 'first=1', 'second=2');

        $response = new Response(
            body: 'Hello',
            headers: $headers,
        );

        $prepared = new ResponsePreparer()->prepare($response);

        self::assertSame(
            ['text/plain'],
            $prepared->headers()->get('Content-Type'),
        );
        self::assertSame(
            ['first=1', 'second=2'],
            $prepared->headers()->get('Set-Cookie'),
        );
        self::assertFalse($response->headers()->has('Content-Length'));
    }

    public function testRepeatedPreparationPreservesValues(): void
    {
        $response = new Response(
            body: 'Hello',
            headers: new Headers()->with('X-Example', 'value'),
        );

        $preparer = new ResponsePreparer();

        $first = $preparer->prepare($response);
        $second = $preparer->prepare($first);

        self::assertSame($first->status(), $second->status());
        self::assertSame($first->body(), $second->body());
        self::assertSame(
            $first->headers()->all(),
            $second->headers()->all(),
        );
    }
}
