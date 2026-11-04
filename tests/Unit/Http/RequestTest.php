<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http;

use Careminate\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testDefaults(): void
    {
        $request = new Request();

        self::assertSame('GET', $request->method);
        self::assertSame('/', $request->target);
        self::assertSame('/', $request->path());
        self::assertSame('', $request->queryString());
        self::assertSame('', $request->body);
    }

    public function testMethodIsNormalized(): void
    {
        $request = new Request('post');

        self::assertSame('POST', $request->method);
    }

    public function testPathAndQueryString(): void
    {
        $request = new Request(
            'GET',
            '/users?page=2&sort=name'
        );

        self::assertSame('/users', $request->path());
        self::assertSame(
            'page=2&sort=name',
            $request->queryString()
        );
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $request = new Request(
            headers: ['Content-Type' => 'application/json']
        );

        self::assertSame(
            'application/json',
            $request->header('CONTENT-TYPE')
        );
    }

    public function testWithMethodsPreserveOriginal(): void
    {
        $original = new Request();

        $modified = $original
        ->withMethod('POST')
        ->withTarget('/items')
        ->withHeader('X-Test', 'value')
        ->withBody('payload');

        self::assertSame('GET', $original->method);
        self::assertSame('/', $original->target);
        self::assertSame('', $original->body);

        self::assertSame('POST', $modified->method);
        self::assertSame('/items', $modified->path());
        self::assertSame(
            'value',
            $modified->header('x-test')
        );
        self::assertSame('payload', $modified->body);
    }

    public function testInvalidMethodIsRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        new Request('GET BAD');
    }

    public function testInvalidTargetIsRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        new Request(target: '/users#fragment');
    }

    public function testHeaderInjectionIsRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        new Request(
            headers: [
'X-Test' => "safe\r\nInjected: value",
]
        );
    }
}
