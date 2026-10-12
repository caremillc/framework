<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Http;

use Flint\Http\ServerRequestFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServerRequestFactoryTest extends TestCase
{
    public function testPreservesMethodTargetAndBody(): void
    {
        $request = new ServerRequestFactory()->create(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/files/a%2Fb?value=one+two',
                'QUERY_STRING' => 'ignored=different',
                'PATH_INFO' => '/ignored',
            ],
            body: "\0\xFF",
        );

        self::assertSame('POST', $request->method());
        self::assertSame(
            '/files/a%2Fb?value=one+two',
            $request->target(),
        );
        self::assertSame('/files/a%2Fb', $request->path());
        self::assertSame('value=one+two', $request->queryString());
        self::assertSame("\0\xFF", $request->body());
    }

    public function testMapsHeadersAndSpecialContentFields(): void
    {
        $request = new ServerRequestFactory()->create([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.test',
            'HTTP_ACCEPT' => 'text/plain, application/json',
            'HTTP_X_EMPTY' => '',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '0',
        ]);

        self::assertSame(
            ['example.test'],
            $request->headers()->get('Host'),
        );
        self::assertSame(
            ['text/plain, application/json'],
            $request->headers()->get('Accept'),
        );
        self::assertSame([''], $request->headers()->get('X-Empty'));
        self::assertSame(
            ['application/json'],
            $request->headers()->get('Content-Type'),
        );
        self::assertSame(
            ['0'],
            $request->headers()->get('Content-Length'),
        );
    }

    public function testIgnoresUnrelatedMetadataAndProxyField(): void
    {
        $request = new ServerRequestFactory()->create([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'SERVER_NAME' => 'internal.example',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_PROXY' => 'http://proxy.example',
            'argv' => ['ignored'],
            123 => 'ignored',
        ]);

        self::assertSame([], $request->headers()->all());
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function mappingOrders(): iterable
    {
        yield 'canonical first' => [true];
        yield 'HTTP mapping first' => [false];
    }

    #[DataProvider('mappingOrders')]
    public function testMatchingMappingsAreAcceptedInEitherOrder(
        bool $canonicalFirst,
    ): void {
        $mappings = $canonicalFirst
            ? [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_CONTENT_TYPE' => ' application/json ',
            ]
            : [
                'HTTP_CONTENT_TYPE' => ' application/json ',
                'CONTENT_TYPE' => 'application/json',
            ];

        $request = new ServerRequestFactory()->create([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
            ...$mappings,
        ]);

        self::assertSame(
            ['application/json'],
            $request->headers()->get('Content-Type'),
        );
    }

    #[DataProvider('mappingOrders')]
    public function testConflictingMappingsAreRejectedInEitherOrder(
        bool $canonicalFirst,
    ): void {
        $mappings = $canonicalFirst
            ? [
                'CONTENT_LENGTH' => '10',
                'HTTP_CONTENT_LENGTH' => '20',
            ]
            : [
                'HTTP_CONTENT_LENGTH' => '20',
                'CONTENT_LENGTH' => '10',
            ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Conflicting server mappings for header "content-length".',
        );

        new ServerRequestFactory()->create([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
            ...$mappings,
        ]);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function invalidMetadata(): iterable
    {
        yield 'missing method' => [
            ['REQUEST_URI' => '/'],
        ];
        yield 'missing target' => [
            ['REQUEST_METHOD' => 'GET'],
        ];
        yield 'non-string method' => [
            ['REQUEST_METHOD' => 123, 'REQUEST_URI' => '/'],
        ];
        yield 'non-string target' => [
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => []],
        ];
    }

    /**
     * @param array<array-key, mixed> $server
     */
    #[DataProvider('invalidMetadata')]
    public function testRequiresStringMetadata(array $server): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ServerRequestFactory()->create($server);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonStringHeaderValues(): iterable
    {
        yield 'integer' => [0];
        yield 'boolean' => [false];
        yield 'null' => [null];
    }

    #[DataProvider('nonStringHeaderValues')]
    public function testHeaderValuesMustBeStrings(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Server header field "CONTENT_LENGTH" must contain a string.',
        );

        new ServerRequestFactory()->create([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
            'CONTENT_LENGTH' => $value,
        ]);
    }

    public function testHeaderControlCharactersAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ServerRequestFactory()->create([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_X_EXAMPLE' => "value\r\nInjected: true",
        ]);
    }

    public function testInvalidTargetIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ServerRequestFactory()->create([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/bad%GG',
        ]);
    }

    public function testInvalidMethodIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ServerRequestFactory()->create([
            'REQUEST_METHOD' => 'GET POST',
            'REQUEST_URI' => '/',
        ]);
    }

    public function testInputChangesDoNotAlterCreatedRequest(): void
    {
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_X_EXAMPLE' => 'original',
        ];

        $request = new ServerRequestFactory()->create($server);

        $server['HTTP_X_EXAMPLE'] = 'changed';

        self::assertSame(
            ['original'],
            $request->headers()->get('X-Example'),
        );
    }
}
