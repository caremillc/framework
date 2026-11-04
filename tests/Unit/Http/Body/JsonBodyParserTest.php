<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Body;

use Careminate\Foundation\Application;
use Careminate\Http\Body\JsonBodyParser;
use Careminate\Http\Exception\HttpException;
use Careminate\Http\Kernel;
use Careminate\Http\Middleware\ExceptionHandler;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\ServerRequestFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonBodyParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<array-key, mixed>}>
     */
    public static function validObjectCases(): iterable
    {
        yield 'simple object' => [
            '{"name":"Careminate"}',
            ['name' => 'Careminate'],
        ];

        yield 'empty object' => ['{}', []];

        yield 'nested object' => [
            '{"task":{"done":false}}',
            ['task' => ['done' => false]],
        ];

        yield 'array inside object' => [
            '{"ids":[1,2,3]}',
            ['ids' => [1, 2, 3]],
        ];

        yield 'Unicode' => [
            '{"text":"مرحبا"}',
            ['text' => 'مرحبا'],
        ];

        yield 'whitespace' => [
            " \t\n{\"a\":1}\r\n",
            ['a' => 1],
        ];

        yield 'large integer preserved' => [
            '{"id":9223372036854775808}',
            ['id' => '9223372036854775808'],
        ];

        yield 'null property' => [
            '{"optional":null}',
            ['optional' => null],
        ];
    }

    /**
     * @param array<array-key, mixed> $expected
     */
    #[DataProvider('validObjectCases')]
    public function testDecodesValidJsonObjects(
        string $body,
        array $expected,
    ): void {
        self::assertSame(
            $expected,
            new JsonBodyParser()->parseObject(
                self::request($body),
            ),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function supportedContentTypes(): iterable
    {
        yield 'standard JSON' => ['application/json'];

        yield 'uppercase JSON' => ['APPLICATION/JSON'];

        yield 'structured suffix' => [
            'application/vnd.api+json',
        ];

        yield 'problem JSON' => [
            'application/problem+json',
        ];

        yield 'UTF-8 charset' => [
            'application/json; charset=utf-8',
        ];

        yield 'quoted UTF-8 charset' => [
            ' application/json ; CHARSET="UTF-8" ',
        ];
    }

    #[DataProvider('supportedContentTypes')]
    public function testSupportsJsonMediaTypes(
        string $contentType,
    ): void {
        self::assertSame(
            ['ok' => true],
            new JsonBodyParser()->parseObject(
                self::request(
                    '{"ok":true}',
                    $contentType,
                ),
            ),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'empty' => [''];

        yield 'whitespace only' => [" \t\r\n"];

        yield 'unfinished object' => ['{'];

        yield 'trailing comma' => ['{"a":1,}'];

        yield 'invalid value' => ['{"a":}'];

        yield 'trailing data' => ['{"a":1} extra'];

        yield 'invalid UTF-8' => [
            '{"a":"' . "\xFF" . '"}',
        ];

        yield 'array root' => ['[1,2]'];

        yield 'string root' => ['"hello"'];

        yield 'numeric root' => ['42'];

        yield 'null root' => ['null'];

        yield 'boolean root' => ['true'];

        yield 'UTF-8 BOM' => [
            "\xEF\xBB\xBF" . '{"a":1}',
        ];
    }

    #[DataProvider('malformedBodies')]
    public function testRejectsMalformedOrNonObjectJson(
        string $body,
    ): void {
        $this->expectHttpStatus(
            400,
            self::request($body),
        );
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unsupportedContentTypes(): iterable
    {
        yield 'missing' => [null];

        yield 'empty' => [''];

        yield 'text' => ['text/plain'];

        yield 'form data' => [
            'application/x-www-form-urlencoded',
        ];

        yield 'wrong charset' => [
            'application/json; charset=iso-8859-1',
        ];

        yield 'duplicate charset' => [
            'application/json; charset=utf-8; charset=utf-8',
        ];

        yield 'unsupported parameter' => [
            'application/json; profile=custom',
        ];

        yield 'wildcard' => [
            'application/*+json',
        ];

        yield 'empty suffix subtype' => [
            'application/+json',
        ];
    }

    #[DataProvider('unsupportedContentTypes')]
    public function testRejectsUnsupportedMediaTypes(
        ?string $contentType,
    ): void {
        $this->expectHttpStatus(
            415,
            self::request(
                '{"ok":true}',
                $contentType,
            ),
        );
    }

    public function testEnforcesExactByteSizeLimit(): void
    {
        $parser = new JsonBodyParser(
            maxBodyBytes: 7,
        );

        self::assertSame(
            ['a' => 1],
            $parser->parseObject(
                self::request('{"a":1}'),
            ),
        );
    }

    public function testRejectsOneByteOverLimit(): void
    {
        $this->expectHttpStatus(
            413,
            self::request('{"a":1}'),
            new JsonBodyParser(6),
        );
    }

    public function testByteLimitUsesBytesNotCharacters(): void
    {
        // This JSON object uses ten UTF-8 bytes.
        $this->expectHttpStatus(
            413,
            self::request('{"x":"é"}'),
            new JsonBodyParser(8),
        );
    }

    public function testSizeLimitPrecedesMediaTypeValidation(): void
    {
        $this->expectHttpStatus(
            413,
            self::request(
                '{"a":1}',
                'text/plain',
            ),
            new JsonBodyParser(6),
        );
    }

    public function testZeroByteLimitRejectsNonemptyBody(): void
    {
        $this->expectHttpStatus(
            413,
            self::request('{}'),
            new JsonBodyParser(0),
        );
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'negative bytes' => [-1, 64];

        yield 'zero depth' => [100, 0];

        yield 'negative depth' => [100, -1];

        yield 'depth above cap' => [100, 513];
    }

    #[DataProvider('invalidConfigurations')]
    public function testRejectsInvalidConfiguration(
        int $bytes,
        int $depth,
    ): void {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new JsonBodyParser(
            $bytes,
            $depth,
        );
    }

    public function testRejectsNestedJsonBeyondConfiguredDepth(): void
    {
        $this->expectHttpStatus(
            400,
            self::request(
                '{"a":{"b":{"c":1}}}',
            ),
            new JsonBodyParser(
                maxDepth: 2,
            ),
        );
    }

    public function testPreservesBodyFromServerRequestFactory(): void
    {
        $request = new ServerRequestFactory(16)->fromServer(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/tasks',
                'CONTENT_TYPE' => 'application/json',
            ],
            '{"title":"x"}',
        );

        self::assertSame(
            ['title' => 'x'],
            new JsonBodyParser()->parseObject($request),
        );
    }

    public function testFactoryAndParserHaveSeparateSizeChecks(): void
    {
        $request = new ServerRequestFactory(16)->fromServer(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/tasks',
                'CONTENT_TYPE' => 'application/json',
            ],
            '{"title":"x"}',
        );

        $this->expectHttpStatus(
            413,
            $request,
            new JsonBodyParser(8),
        );
    }

    public function testExceptionHandlerConvertsParserErrorsToSafeHttpResponses(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->middleware(
            new ExceptionHandler(),
        );

        $parser = new JsonBodyParser(20);

        $kernel->router()->post(
            '/tasks',
            static function (
                Request $request,
            ) use ($parser): Response {
                $parsed = $parser->parseObject(
                    $request,
                );

                $title = $parsed['title'] ?? null;

                return new Response(
                    200,
                    [],
                    is_string($title) ? $title : '',
                );
            },
        );

        $cases = [
            [
                self::request('{"title":"ok"}'),
                200,
                'ok',
            ],
            [
                self::request('{"title":}'),
                400,
                'Bad Request',
            ],
            [
                self::request(
                    '{"title":"ok"}',
                    'text/plain',
                ),
                415,
                'Unsupported Media Type',
            ],
            [
                self::request(
                    '{"title":"much too long"}',
                ),
                413,
                'Content Too Large',
            ],
        ];

        foreach ($cases as [$request, $status, $body]) {
            $response = $kernel->handle(
                $request->withTarget('/tasks'),
            );

            self::assertSame(
                $status,
                $response->status,
            );

            self::assertSame(
                $body,
                $response->body,
            );

            if ($status !== 200) {
                self::assertSame(
                    'no-store',
                    $response->header('Cache-Control'),
                );

                self::assertSame(
                    'nosniff',
                    $response->header(
                        'X-Content-Type-Options',
                    ),
                );
            }
        }
    }

    public function testParserHasNoCrossRequestState(): void
    {
        $parser = new JsonBodyParser();

        self::assertSame(
            ['a' => 1],
            $parser->parseObject(
                self::request('{"a":1}'),
            ),
        );

        self::assertSame(
            ['b' => 2],
            $parser->parseObject(
                self::request('{"b":2}'),
            ),
        );

        self::assertSame(
            [],
            $parser->parseObject(
                self::request('{}'),
            ),
        );
    }

    private function expectHttpStatus(
        int $status,
        Request $request,
        ?JsonBodyParser $parser = null,
    ): void {
        try {
            ($parser ?? new JsonBodyParser())->parseObject(
                $request,
            );

            self::fail(
                'Expected an HTTP parsing exception.',
            );
        } catch (HttpException $exception) {
            self::assertSame(
                $status,
                $exception->status,
            );
        }
    }

    private static function request(
        string $body,
        ?string $contentType = 'application/json',
    ): Request {
        return new Request(
            'POST',
            '/',
            $contentType === null
                ? []
                : ['Content-Type' => $contentType],
            $body,
        );
    }
}
