<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Flint\Http\Headers;
use Flint\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testDefaultsToGetRoot(): void
    {
        $request = new Request();

        self::assertSame('GET', $request->method());
        self::assertSame('/', $request->target());
        self::assertSame('/', $request->path());
        self::assertNull($request->queryString());
        self::assertSame([], $request->headers()->all());
        self::assertSame('', $request->body());
    }

    public function testMethodCaseIsPreserved(): void
    {
        $request = new Request(method: 'get');

        self::assertSame('get', $request->method());
    }

    public function testSeparatesPathFromRawQueryAtFirstQuestionMark(): void
    {
        $target = '/search?q=first?second&tag=a&tag=b';
        $request = new Request(target: $target);

        self::assertSame($target, $request->target());
        self::assertSame('/search', $request->path());
        self::assertSame(
            'q=first?second&tag=a&tag=b',
            $request->queryString(),
        );
    }

    public function testExplicitEmptyQueryRemainsPresent(): void
    {
        $request = new Request(target: '/search?');

        self::assertSame('/search?', $request->target());
        self::assertSame('/search', $request->path());
        self::assertSame('', $request->queryString());
    }

    public function testEncodedPathAndQueryRemainUnchanged(): void
    {
        $request = new Request(
            target: '/files/a%2Fb?value=%3F&space=a+b',
        );

        self::assertSame('/files/a%2Fb', $request->path());
        self::assertSame(
            'value=%3F&space=a+b',
            $request->queryString(),
        );
    }

    public function testHeadersAndBodyArePreserved(): void
    {
        $headers = new Headers()->with(
            'Content-Type',
            'application/octet-stream',
        );

        $body = "\0\xFF\r\n";

        $request = new Request(
            method: 'POST',
            target: '/upload',
            headers: $headers,
            body: $body,
        );

        self::assertSame($headers, $request->headers());
        self::assertSame($body, $request->body());
        self::assertSame('POST', $request->method());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidMethods(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['GET POST'];
        yield 'newline' => ["GET\n"];
        yield 'separator' => ['GET/POST'];
    }

    #[DataProvider('invalidMethods')]
    public function testRejectsInvalidMethod(string $method): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Request methods must be non-empty HTTP tokens.',
        );

        new Request(method: $method);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTargets(): iterable
    {
        yield 'absolute URI' => ['https://example.test/path'];
        yield 'asterisk form' => ['*'];
        yield 'relative path' => ['products'];
        yield 'fragment' => ['/products#details'];
        yield 'space' => ['/bad path'];
        yield 'backslash' => ['/bad\\path'];
        yield 'invalid escape' => ['/bad%GG'];
        yield 'incomplete escape' => ['/bad%2'];
        yield 'newline' => ["/bad\npath"];
    }

    #[DataProvider('invalidTargets')]
    public function testRejectsInvalidTarget(string $target): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Request targets must be valid origin-form paths with an optional query.',
        );

        new Request(target: $target);
    }
}
