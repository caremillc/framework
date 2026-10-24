<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http;

use Careminate\Http\ServerRequestFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ServerRequestFactoryTest extends TestCase
{
    public function testCreatesRequestFromServerData(): void
    {
        $factory = new ServerRequestFactory();

        $request = $factory->fromServer(
            [
                'REQUEST_METHOD' => 'post',
                'REQUEST_URI' => '/users?page=2',
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
            ],
            '{"name":"Careminate"}',
        );

        self::assertSame('POST', $request->method);
        self::assertSame('/users', $request->path());
        self::assertSame('page=2', $request->queryString());
        self::assertSame(
            'application/json',
            $request->header('Accept'),
        );
        self::assertSame(
            'application/json',
            $request->header('Content-Type'),
        );
        self::assertSame(
            '{"name":"Careminate"}',
            $request->body,
        );
    }

    public function testRejectsMissingMethod(): void
    {
        $factory = new ServerRequestFactory();

        $this->expectException(
            InvalidArgumentException::class,
        );

        $factory->fromServer([
            'REQUEST_URI' => '/',
        ]);
    }

    public function testRejectsMissingTarget(): void
    {
        $factory = new ServerRequestFactory();

        $this->expectException(
            InvalidArgumentException::class,
        );

        $factory->fromServer([
            'REQUEST_METHOD' => 'GET',
        ]);
    }

    public function testRejectsNonStringMethod(): void
    {
        $factory = new ServerRequestFactory();

        $this->expectException(
            InvalidArgumentException::class,
        );

        $factory->fromServer([
            'REQUEST_METHOD' => 123,
            'REQUEST_URI' => '/',
        ]);
    }

    public function testRejectsNonStringTarget(): void
    {
        $factory = new ServerRequestFactory();

        $this->expectException(
            InvalidArgumentException::class,
        );

        $factory->fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => ['/'],
        ]);
    }

    public function testRejectsInvalidMethod(): void
    {
        $factory = new ServerRequestFactory();

        $this->expectException(
            InvalidArgumentException::class,
        );

        $factory->fromServer([
            'REQUEST_METHOD' => 'GET POST',
            'REQUEST_URI' => '/',
        ]);
    }

    public function testRejectsInvalidTarget(): void
    {
        $factory = new ServerRequestFactory();

        $this->expectException(
            InvalidArgumentException::class,
        );

        $factory->fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'https://example.com/',
        ]);
    }

    public function testNormalizesHeaderNames(): void
    {
        $factory = new ServerRequestFactory();

        $request = $factory->fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_X_REQUEST_ID' => 'request-123',
        ]);

        self::assertSame(
            'request-123',
            $request->header('x-request-id'),
        );
    }

    public function testMapsContentLength(): void
    {
        $factory = new ServerRequestFactory();

        $request = $factory->fromServer([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
            'CONTENT_LENGTH' => '5',
        ], 'hello');

        self::assertSame(
            '5',
            $request->header('Content-Length'),
        );
    }

    public function testRejectsNonStringHeader(): void
    {
        $factory = new ServerRequestFactory();

        $this->expectException(
            InvalidArgumentException::class,
        );

        $factory->fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_ACCEPT' => ['application/json'],
        ]);
    }

    public function testRejectsDuplicateNormalizedHeaders(): void
    {
        $factory = new ServerRequestFactory();

        $this->expectException(
            InvalidArgumentException::class,
        );

        $factory->fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_CONTENT_TYPE' => 'text/plain',
            'CONTENT_TYPE' => 'application/json',
        ]);
    }

    public function testRejectsOversizedBody(): void
    {
        $factory = new ServerRequestFactory(4);

        $this->expectException(
            InvalidArgumentException::class,
        );

        $factory->fromServer([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
        ], '12345');
    }

    public function testAcceptsBodyAtExactLimit(): void
    {
        $factory = new ServerRequestFactory(4);

        $request = $factory->fromServer([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
        ], '1234');

        self::assertSame('1234', $request->body);
    }

    public function testRejectsNegativeBodyLimit(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new ServerRequestFactory(-1);
    }

    public function testPreservesBinaryBody(): void
    {
        $factory = new ServerRequestFactory();

        $body = "\x00\x01\xFF";

        $request = $factory->fromServer([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/binary',
        ], $body);

        self::assertSame($body, $request->body);
    }

    public function testIgnoresUnrelatedServerVariables(): void
    {
        $factory = new ServerRequestFactory();

        $request = $factory->fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'SERVER_NAME' => 'localhost',
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        self::assertSame('GET', $request->method);
        self::assertSame('/', $request->path());
        self::assertSame([], $request->headers);
    }

    public function testRejectsInvalidHeaderValue(): void
    {
        $factory = new ServerRequestFactory();

        $this->expectException(
            InvalidArgumentException::class,
        );

        $factory->fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_X_TEST' => "valid\r\nInjected: yes",
        ]);
    }

    public function testRejectsEmptyHeaderName(): void
    {
        $factory = new ServerRequestFactory();

        $this->expectException(
            InvalidArgumentException::class,
        );

        $factory->fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_' => 'invalid',
        ]);
    }
}
