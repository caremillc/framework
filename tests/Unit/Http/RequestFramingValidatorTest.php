<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Flint\Http\Headers;
use Flint\Http\Request;
use Flint\Http\RequestFramingValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestFramingValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function matchingLengths(): iterable
    {
        yield 'empty' => ['0', ''];
        yield 'zero padding' => ['0000', ''];
        yield 'ordinary length' => ['3', 'abc'];
        yield 'padded length' => ['0003', 'abc'];
    }

    #[DataProvider('matchingLengths')]
    public function testAcceptsMatchingLength(
        string $length,
        string $body,
    ): void {
        $request = new Request(
            method: 'POST',
            headers: new Headers()->with('Content-Length', $length),
            body: $body,
        );

        new RequestFramingValidator()->validate($request);

        self::assertSame($body, $request->body());
        self::assertSame(
            [$length],
            $request->headers()->get('Content-Length'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidLengths(): iterable
    {
        yield 'empty' => [''];
        yield 'negative' => ['-1'];
        yield 'positive sign' => ['+1'];
        yield 'fraction' => ['1.0'];
        yield 'combined values' => ['1,1'];
        yield 'embedded space' => ['1 0'];
    }

    #[DataProvider('invalidLengths')]
    public function testRejectsInvalidLengthSyntax(string $length): void
    {
        $request = new Request(
            headers: new Headers()->with('Content-Length', $length),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Request Content-Length must contain one decimal byte count.',
        );

        new RequestFramingValidator()->validate($request);
    }

    public function testHugeLengthIsRejectedWithoutIntegerConversion(): void
    {
        $request = new Request(
            headers: new Headers()->with(
                'Content-Length',
                str_repeat('9', 100),
            ),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Request Content-Length does not match the captured body.',
        );

        new RequestFramingValidator()->validate($request);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mismatchedLengths(): iterable
    {
        yield 'declared shorter' => ['2'];
        yield 'declared longer' => ['4'];
    }

    #[DataProvider('mismatchedLengths')]
    public function testRejectsLengthMismatch(string $length): void
    {
        $request = new Request(
            method: 'POST',
            headers: new Headers()->with('Content-Length', $length),
            body: 'abc',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Request Content-Length does not match the captured body.',
        );

        new RequestFramingValidator()->validate($request);
    }

    public function testRejectsRepeatedLengthValues(): void
    {
        $request = new Request(
            headers: new Headers()->with('Content-Length', '0', '0'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Request Content-Length must contain one decimal byte count.',
        );

        new RequestFramingValidator()->validate($request);
    }

    public function testRejectsTransferEncoding(): void
    {
        $request = new Request(
            headers: new Headers()->with('Transfer-Encoding', 'chunked'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Transfer-Encoding is not supported by this request capture implementation.',
        );

        new RequestFramingValidator()->validate($request);
    }

    public function testAllowsAbsentLengthForEmptyBody(): void
    {
        $request = new Request();

        new RequestFramingValidator()->validate($request);

        self::assertFalse($request->headers()->has('Content-Length'));
    }

    public function testAllowsAbsentLengthForServerSuppliedBody(): void
    {
        $request = new Request(method: 'POST', body: 'abc');

        new RequestFramingValidator()->validate($request);

        self::assertSame('abc', $request->body());
        self::assertFalse($request->headers()->has('Content-Length'));
    }
}
