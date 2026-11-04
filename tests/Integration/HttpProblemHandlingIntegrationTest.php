<?php

declare(strict_types=1);

namespace Careminate\Tests\Integration;

use Careminate\Foundation\Application;
use Careminate\Http\Body\JsonBodyParser;
use Careminate\Http\Emission\BufferedResponseOutput;
use Careminate\Http\Emission\ResponseEmitter;
use Careminate\Http\Exception\HttpException;
use Careminate\Http\Exception\ProblemResponseFactory;
use Careminate\Http\Kernel;
use Careminate\Http\Middleware\ProblemExceptionHandler;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Runtime\HttpRunner;
use Careminate\Http\ServerRequestFactory;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HttpProblemHandlingIntegrationTest extends TestCase
{
    /**
     * @return iterable<string, array{
     *     array<string, mixed>,
     *     string,
     *     int,
     *     int
     * }>
     */
    public static function rejectedIngressCases(): iterable
    {
        yield 'missing method' => [
            ['REQUEST_METHOD' => ''], '', 16, 400,
        ];

        yield 'invalid method token' => [
            ['REQUEST_METHOD' => 'GE T'], '', 16, 400,
        ];

        yield 'relative target' => [
            ['REQUEST_URI' => 'relative'], '', 16, 400,
        ];

        yield 'empty target' => [
            ['REQUEST_URI' => ''], '', 16, 400,
        ];

        yield 'newline in header' => [
            ['HTTP_X_TRACE' => "a\r\nInjected: b"], '', 16, 400,
        ];

        yield 'nonstring header value' => [
            ['HTTP_ACCEPT' => ['json']], '', 16, 400,
        ];

        yield 'length mismatch' => [
            ['CONTENT_LENGTH' => '3'], 'ab', 16, 400,
        ];

        yield 'negative length' => [
            ['CONTENT_LENGTH' => '-2'], '', 16, 400,
        ];

        yield 'conflicting aliases' => [
            [
                'CONTENT_LENGTH' => '2',
                'HTTP_CONTENT_LENGTH' => '3',
            ],
            'ab',
            16,
            400,
        ];

        yield 'ambiguous framing' => [
            [
                'CONTENT_LENGTH' => '2',
                'HTTP_TRANSFER_ENCODING' => 'chunked',
            ],
            'ab',
            16,
            400,
        ];

        yield 'body exceeds limit' => [
            [], '12345', 4, 413,
        ];

        yield 'declared length exceeds limit' => [
            ['CONTENT_LENGTH' => '9'], '1234', 4, 413,
        ];

        yield 'huge declared length' => [
            [
                'CONTENT_LENGTH' =>
                    '9999999999999999999999999999999999',
            ],
            '',
            4,
            413,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('rejectedIngressCases')]
    public function testOptInSafeRunnerEmitsGenericInputProblem(
        array $overrides,
        string $body,
        int $limit,
        int $expectedStatus,
    ): void {
        $kernel = new Kernel(new Application());
        $calls = 0;

        $kernel->router()->post(
            '/submit',
            static function (Request $request) use (&$calls): Response {
                ++$calls;

                return new Response(
                    200,
                    [],
                    'should not execute',
                );
            },
        );

        $output = new BufferedResponseOutput();

        $runner = self::runner(
            $kernel,
            $output,
            $limit,
        );

        $response = $runner->runSafely(
            array_replace(
                self::server(),
                $overrides,
            ),
            $body,
        );

        self::assertSame(
            $expectedStatus,
            $response->status,
        );

        self::assertSame(
            $expectedStatus,
            $output->status(),
        );

        self::assertSame(
            $response->body,
            $output->body(),
        );

        self::assertSame(
            'application/problem+json',
            $output->headers()['content-type'] ?? null,
        );

        self::assertSame(
            'no-store',
            $response->header('Cache-Control'),
        );

        self::assertSame(
            'nosniff',
            $response->header('X-Content-Type-Options'),
        );

        self::assertSame(
            [
                'type' => 'about:blank',
                'title' => $expectedStatus === 413
                    ? 'Content Too Large'
                    : 'Bad Request',
                'status' => $expectedStatus,
            ],
            json_decode(
                $response->body,
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );

        self::assertSame(0, $calls);

        self::assertFalse(
            $kernel->router()->isFrozen(),
        );

        self::assertTrue(
            $runner->hasExecuted(),
        );
    }

    public function testOriginalRunnerStillThrowsWithoutEmitting(): void
    {
        $kernel = new Kernel(new Application());

        $output = new BufferedResponseOutput();

        $runner = self::runner(
            $kernel,
            $output,
        );

        try {
            $runner->run(
                self::server([
                    'CONTENT_LENGTH' => '8',
                ]),
                'short',
            );

            self::fail(
                'Expected the legacy exception contract.',
            );
        } catch (InvalidArgumentException) {
            self::assertNull(
                $output->status(),
            );

            self::assertSame(
                '',
                $output->body(),
            );

            self::assertTrue(
                $runner->hasExecuted(),
            );
        }
    }

    public function testSafeRunnerRemainsOneShotAfterRejectedInput(): void
    {
        $runner = self::runner(
            new Kernel(new Application()),
            new BufferedResponseOutput(),
        );

        self::assertSame(
            400,
            $runner->runSafely(
                self::server([
                    'CONTENT_LENGTH' => '-1',
                ]),
            )->status,
        );

        $this->expectException(
            LogicException::class,
        );

        $runner->runSafely(
            self::server(),
        );
    }

    public function testSafeRunnerProcessesValidInputNormally(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->post(
            '/submit',
            static fn (Request $request): Response =>
                new Response(
                    201,
                    ['X-Handled' => 'yes'],
                    $request->body,
                ),
        );

        $output = new BufferedResponseOutput();

        $response = self::runner(
            $kernel,
            $output,
        )->runSafely(
            self::server([
                'CONTENT_LENGTH' => '4',
            ]),
            'test',
        );

        self::assertSame(
            201,
            $response->status,
        );

        self::assertSame(
            201,
            $output->status(),
        );

        self::assertSame(
            'test',
            $output->body(),
        );

        self::assertSame(
            'yes',
            $output->headers()['x-handled'] ?? null,
        );
    }

    public function testSafeRunnerStillSuppressesHeadResponseBody(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/submit',
            static fn (Request $request): Response =>
                new Response(
                    200,
                    ['X-Head' => 'yes'],
                    'not transmitted',
                ),
        );

        $output = new BufferedResponseOutput();

        $response = self::runner(
            $kernel,
            $output,
        )->runSafely(
            self::server([
                'REQUEST_METHOD' => 'HEAD',
            ]),
        );

        self::assertSame(
            200,
            $response->status,
        );

        self::assertSame(
            '',
            $response->body,
        );

        self::assertSame(
            '',
            $output->body(),
        );

        self::assertSame(
            'yes',
            $output->headers()['x-head'] ?? null,
        );
    }

    public function testInvalidHeadRequestEmitsBodylessProblem(): void
    {
        $kernel = new Kernel(new Application());

        $output = new BufferedResponseOutput();

        $response = self::runner(
            $kernel,
            $output,
        )->runSafely(
            self::server([
                'REQUEST_METHOD' => 'HEAD',
                'CONTENT_LENGTH' => '9',
            ]),
            'abc',
        );

        self::assertSame(
            400,
            $response->status,
        );

        self::assertSame(
            '',
            $response->body,
        );

        self::assertSame(
            '',
            $output->body(),
        );

        self::assertSame(
            400,
            $output->status(),
        );

        self::assertSame(
            'application/problem+json',
            $response->header('Content-Type'),
        );
    }

    public function testSafeGlobalsRejectsOversizedDeclarationBeforeReading(): void
    {
        $kernel = new Kernel(new Application());

        $output = new BufferedResponseOutput();

        $response = self::runner(
            $kernel,
            $output,
            4,
        )->runFromGlobalsSafely(
            self::server([
                'CONTENT_LENGTH' => '100',
            ]),
        );

        self::assertSame(
            413,
            $response->status,
        );

        self::assertSame(
            413,
            $output->status(),
        );

        self::assertFalse(
            $kernel->router()->isFrozen(),
        );
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function parserErrorCases(): iterable
    {
        yield 'malformed JSON' => [
            '{"a":}',
            'application/json',
            400,
        ];

        yield 'unsupported media type' => [
            '{"a":1}',
            'text/plain',
            415,
        ];

        yield 'oversized parser body' => [
            '{"a":"more than ten"}',
            'application/json',
            413,
        ];
    }

    #[DataProvider('parserErrorCases')]
    public function testParserFailuresProduceSanitizedProblemResponses(
        string $body,
        string $contentType,
        int $expectedStatus,
    ): void {
        $kernel = self::jsonKernel(
            new JsonBodyParser(10),
        );

        $output = new BufferedResponseOutput();

        $response = self::runner(
            $kernel,
            $output,
        )->runSafely(
            self::server([
                'CONTENT_TYPE' => $contentType,
            ]),
            $body,
        );

        self::assertSame(
            $expectedStatus,
            $response->status,
        );

        self::assertSame(
            $expectedStatus,
            $output->status(),
        );

        self::assertSame(
            'application/problem+json',
            $response->header('Content-Type'),
        );

        self::assertSame(
            'no-store',
            $response->header('Cache-Control'),
        );

        self::assertSame(
            'nosniff',
            $response->header('X-Content-Type-Options'),
        );

        self::assertSame(
            $response->body,
            $output->body(),
        );

        self::assertStringNotContainsString(
            $body,
            $response->body,
        );
    }

    public function testValidJsonRetainsNormalSuccessResponse(): void
    {
        $kernel = self::jsonKernel(
            new JsonBodyParser(10),
        );

        $output = new BufferedResponseOutput();

        $response = self::runner(
            $kernel,
            $output,
        )->runSafely(
            self::server([
                'CONTENT_TYPE' => 'application/json',
            ]),
            '{"a":1}',
        );

        self::assertSame(
            200,
            $response->status,
        );

        self::assertSame(
            'parsed',
            $response->body,
        );

        self::assertSame(
            'parsed',
            $output->body(),
        );
    }

    public function testProblemMiddlewareHidesUnexpectedExceptionMessages(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->middleware(
            new ProblemExceptionHandler(),
        );

        $kernel->router()->post(
            '/submit',
            static function (Request $request): Response {
                throw new RuntimeException(
                    'internal-api-secret',
                );
            },
        );

        $output = new BufferedResponseOutput();

        $response = self::runner(
            $kernel,
            $output,
        )->runSafely(
            self::server(),
        );

        self::assertSame(
            500,
            $response->status,
        );

        self::assertSame(
            [
                'type' => 'about:blank',
                'title' => 'Internal Server Error',
                'status' => 500,
            ],
            json_decode(
                $output->body(),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );

        self::assertStringNotContainsString(
            'internal-api-secret',
            $response->body,
        );
    }

    public function testProblemMiddlewareHidesHttpExceptionDetail(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->middleware(
            new ProblemExceptionHandler(),
        );

        $kernel->router()->post(
            '/submit',
            static function (Request $request): Response {
                throw new HttpException(
                    415,
                    'untrusted request metadata: secret',
                );
            },
        );

        $response = $kernel->handle(
            new Request('POST', '/submit'),
        );

        self::assertSame(
            415,
            $response->status,
        );

        self::assertStringNotContainsString(
            'secret',
            $response->body,
        );

        self::assertSame(
            'no-store',
            $response->header('Cache-Control'),
        );
    }

    public function testHeadProblemResponseBodyIsSuppressed(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->middleware(
            new ProblemExceptionHandler(),
        );

        $kernel->router()->get(
            '/submit',
            static function (Request $request): Response {
                throw new HttpException(
                    415,
                    'private',
                );
            },
        );

        $output = new BufferedResponseOutput();

        $response = self::runner(
            $kernel,
            $output,
        )->runSafely(
            self::server([
                'REQUEST_METHOD' => 'HEAD',
            ]),
        );

        self::assertSame(
            415,
            $response->status,
        );

        self::assertSame(
            '',
            $response->body,
        );

        self::assertSame(
            '',
            $output->body(),
        );

        self::assertSame(
            'application/problem+json',
            $response->header('Content-Type'),
        );
    }

    public function testValidRequestHandlerFailureWithoutMiddlewareStillPropagates(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->post(
            '/submit',
            static function (Request $request): Response {
                throw new RuntimeException(
                    'handler failed',
                );
            },
        );

        $output = new BufferedResponseOutput();

        $runner = self::runner(
            $kernel,
            $output,
        );

        try {
            $runner->runSafely(
                self::server(),
            );

            self::fail(
                'Expected handler error to propagate without middleware.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                'handler failed',
                $exception->getMessage(),
            );
        }

        self::assertNull(
            $output->status(),
        );
    }

    public function testProblemFactoryRejectsNonErrorStatus(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new ProblemResponseFactory()->create(204);
    }

    /**
     * @param array<string, mixed> $extra
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

    private static function runner(
        Kernel $kernel,
        BufferedResponseOutput $output,
        int $maxBytes = 1048576,
    ): HttpRunner {
        return new HttpRunner(
            new ServerRequestFactory($maxBytes),
            $kernel,
            new ResponseEmitter($output),
        );
    }

    private static function jsonKernel(
        JsonBodyParser $parser,
    ): Kernel {
        $kernel = new Kernel(new Application());

        $kernel->middleware(
            new ProblemExceptionHandler(),
        );

        $kernel->router()->post(
            '/submit',
            static function (
                Request $request,
            ) use ($parser): Response {
                $parser->parseObject($request);

                return new Response(
                    200,
                    [],
                    'parsed',
                );
            },
        );

        return $kernel;
    }
}
