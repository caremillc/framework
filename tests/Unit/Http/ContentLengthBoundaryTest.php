<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http;

use Careminate\Foundation\Application;
use Careminate\Http\Body\JsonBodyParser;
use Careminate\Http\Emission\BufferedResponseOutput;
use Careminate\Http\Emission\ResponseEmitter;
use Careminate\Http\Kernel;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Runtime\HttpRunner;
use Careminate\Http\ServerRequestFactory;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContentLengthBoundaryTest extends TestCase
{
    /**
     * @return iterable<string, array{
     *     array<string, string>,
     *     string,
     *     ?string
     * }>
     */
    public static function acceptedLengths(): iterable
    {
        yield 'absent and empty' => [
            [], '', null,
        ];

        yield 'absent and binary' => [
            [], "\x00\xFF", null,
        ];

        yield 'zero and empty' => [
            ['CONTENT_LENGTH' => '0'], '', '0',
        ];

        yield 'zero with leading zeroes' => [
            ['CONTENT_LENGTH' => '000'], '', '000',
        ];

        yield 'exact length' => [
            ['CONTENT_LENGTH' => '4'], 'test', '4',
        ];

        yield 'leading zeroes' => [
            ['CONTENT_LENGTH' => '0004'], 'test', '0004',
        ];

        yield 'HTTP alias' => [
            ['HTTP_CONTENT_LENGTH' => '4'], 'test', '4',
        ];

        yield 'matching aliases' => [
            [
                'CONTENT_LENGTH' => '4',
                'HTTP_CONTENT_LENGTH' => '4',
            ],
            'test',
            '4',
        ];

        yield 'matching aliases reversed' => [
            [
                'HTTP_CONTENT_LENGTH' => '4',
                'CONTENT_LENGTH' => '4',
            ],
            'test',
            '4',
        ];

        yield 'UTF-8 length is bytes' => [
            ['CONTENT_LENGTH' => '10'],
            '{"x":"é"}',
            '10',
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('acceptedLengths')]
    public function testAcceptsMatchingContentLength(
        array $headers,
        string $body,
        ?string $expectedLength,
    ): void {
        $request = new ServerRequestFactory()->fromServer(
            self::server($headers),
            $body,
        );

        self::assertSame(
            $body,
            $request->body,
        );

        self::assertSame(
            $expectedLength,
            $request->header('Content-Length'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedLengths(): iterable
    {
        yield 'empty' => [''];
        yield 'negative' => ['-1'];
        yield 'plus prefix' => ['+4'];
        yield 'leading space' => [' 4'];
        yield 'trailing space' => ['4 '];
        yield 'fraction' => ['4.0'];
        yield 'scientific notation' => ['4e0'];
        yield 'hexadecimal' => ['0x4'];
        yield 'comma separated list' => ['4, 4'];
        yield 'trailing comma' => ['4,'];
        yield 'semicolon' => ['4;4'];
        yield 'newline' => ["4\n"];
        yield 'tab' => ["4\t"];
        yield 'null byte' => ["4\0"];
        yield 'Unicode decimal' => ['４'];
    }

    #[DataProvider('malformedLengths')]
    public function testRejectsInvalidContentLengthSyntax(
        string $declared,
    ): void {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new ServerRequestFactory()->fromServer(
            self::server([
                'CONTENT_LENGTH' => $declared,
            ]),
            'test',
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mismatchedLengths(): iterable
    {
        yield 'short declaration' => ['2', 'abc'];
        yield 'long declaration' => ['5', 'abc'];
        yield 'zero but nonempty' => ['0', 'x'];
        yield 'declared body missing' => ['1', ''];
        yield 'UTF-8 character count instead of bytes' => [
            '9',
            '{"x":"é"}',
        ];
        yield 'binary mismatch' => [
            '2',
            "\x00\x01\xFF",
        ];
    }

    #[DataProvider('mismatchedLengths')]
    public function testRejectsBodyLengthMismatch(
        string $length,
        string $body,
    ): void {
        $this->expectException(
            InvalidArgumentException::class,
        );

        $this->expectExceptionMessage(
            'HTTP Content-Length does not match the received body size.',
        );

        new ServerRequestFactory()->fromServer(
            self::server([
                'CONTENT_LENGTH' => $length,
            ]),
            $body,
        );
    }

    /**
     * @return iterable<string, array{
     *     array<string, string>,
     *     string
     * }>
     */
    public static function invalidFramingCases(): iterable
    {
        yield 'conflicting aliases' => [
            [
                'CONTENT_LENGTH' => '4',
                'HTTP_CONTENT_LENGTH' => '5',
            ],
            'test',
        ];

        yield 'different textual aliases' => [
            [
                'CONTENT_LENGTH' => '04',
                'HTTP_CONTENT_LENGTH' => '4',
            ],
            'test',
        ];

        yield 'reversed conflicting aliases' => [
            [
                'HTTP_CONTENT_LENGTH' => '5',
                'CONTENT_LENGTH' => '4',
            ],
            'test',
        ];

        yield 'content length with transfer encoding' => [
            [
                'CONTENT_LENGTH' => '4',
                'HTTP_TRANSFER_ENCODING' => 'chunked',
            ],
            'test',
        ];

        yield 'HTTP length with transfer encoding' => [
            [
                'HTTP_CONTENT_LENGTH' => '4',
                'HTTP_TRANSFER_ENCODING' => 'chunked',
            ],
            'test',
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('invalidFramingCases')]
    public function testRejectsAmbiguousFraming(
        array $headers,
        string $body,
    ): void {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new ServerRequestFactory()->fromServer(
            self::server($headers),
            $body,
        );
    }

    public function testRejectsDeclaredLengthBeyondConfiguredLimit(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        $this->expectExceptionMessage(
            'configured size limit',
        );

        new ServerRequestFactory(4)->fromServer(
            self::server([
                'CONTENT_LENGTH' => '5',
            ]),
            'test',
        );
    }

    public function testRejectsBodyBeyondConfiguredLimitWithoutLength(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        $this->expectExceptionMessage(
            'configured size limit',
        );

        new ServerRequestFactory(4)->fromServer(
            self::server(),
            '12345',
        );
    }

    public function testRejectsHugeDeclaredLengthWithoutOverflow(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        $this->expectExceptionMessage(
            'configured size limit',
        );

        new ServerRequestFactory()->fromServer(
            self::server([
                'CONTENT_LENGTH' => str_repeat('9', 200),
            ]),
        );
    }

    public function testZeroLimitAcceptsEmptyBody(): void
    {
        $request = new ServerRequestFactory(0)->fromServer(
            self::server([
                'CONTENT_LENGTH' => '0',
            ]),
            '',
        );

        self::assertSame(
            '',
            $request->body,
        );
    }

    public function testZeroLimitRejectsDeclaredNonzeroLength(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new ServerRequestFactory(0)->fromServer(
            self::server([
                'CONTENT_LENGTH' => '1',
            ]),
        );
    }

    public function testFromGlobalsRejectsOversizedDeclarationBeforeReading(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        $this->expectExceptionMessage(
            'configured size limit',
        );

        new ServerRequestFactory(4)->fromGlobals(
            self::server([
                'CONTENT_LENGTH' => '500',
            ]),
        );
    }

    public function testFromGlobalsRejectsAmbiguousFramingBeforeReading(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        $this->expectExceptionMessage(
            'framing headers',
        );

        new ServerRequestFactory(4)->fromGlobals(
            self::server([
                'CONTENT_LENGTH' => '4',
                'HTTP_TRANSFER_ENCODING' => 'chunked',
            ]),
        );
    }

    public function testRuntimeDoesNotDispatchOrEmitInvalidLength(): void
    {
        $kernel = new Kernel(new Application());

        $calls = 0;

        $kernel->router()->post(
            '/submit',
            static function (
                Request $request,
            ) use (&$calls): Response {
                ++$calls;

                return new Response(
                    200,
                    [],
                    'processed',
                );
            },
        );

        $output = new BufferedResponseOutput();

        $runner = new HttpRunner(
            new ServerRequestFactory(20),
            $kernel,
            new ResponseEmitter($output),
        );

        try {
            $runner->run(
                self::server([
                    'CONTENT_LENGTH' => '10',
                ]),
                'test',
            );

            self::fail(
                'Expected Content-Length mismatch rejection.',
            );
        } catch (InvalidArgumentException) {
            self::assertSame(0, $calls);
        }

        self::assertTrue(
            $runner->hasExecuted(),
        );

        self::assertFalse(
            $kernel->router()->isFrozen(),
        );

        self::assertNull(
            $output->status(),
        );

        self::assertSame(
            [],
            $output->headers(),
        );

        self::assertSame(
            '',
            $output->body(),
        );

        $this->expectException(
            LogicException::class,
        );

        $runner->run(
            self::server(),
            'test',
        );
    }

    public function testRuntimeEmitsExactBodyWhenLengthMatches(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->post(
            '/submit',
            static fn (Request $request): Response =>
                new Response(
                    200,
                    [],
                    $request->body,
                ),
        );

        $output = new BufferedResponseOutput();

        $runner = new HttpRunner(
            new ServerRequestFactory(4),
            $kernel,
            new ResponseEmitter($output),
        );

        $response = $runner->run(
            self::server([
                'CONTENT_LENGTH' => '4',
            ]),
            "\x00\xFFab",
        );

        self::assertSame(
            200,
            $response->status,
        );

        self::assertSame(
            "\x00\xFFab",
            $output->body(),
        );

        self::assertSame(
            200,
            $output->status(),
        );
    }

    public function testJsonParserWorksWithMatchingContentLength(): void
    {
        $request = new ServerRequestFactory(64)->fromServer(
            self::server([
                'CONTENT_TYPE' => 'application/json',
                'CONTENT_LENGTH' => '7',
            ]),
            '{"a":1}',
        );

        self::assertSame(
            ['a' => 1],
            new JsonBodyParser()->parseObject($request),
        );
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, mixed>
     */
    private static function server(
        array $extra = [],
    ): array {
        return array_replace(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/submit',
            ],
            $extra,
        );
    }
}
