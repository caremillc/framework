<?php

declare (strict_types=1);

namespace Careminate\Tests\Integration;

use Careminate\Foundation\Application;
use Careminate\Http\Body\JsonBodyParser;
use Careminate\Http\Emission\BufferedResponseOutput;
use Careminate\Http\Emission\ResponseEmitter;
use Careminate\Http\Exception\HttpException;
use Careminate\Http\Kernel;
use Careminate\Http\Middleware\Middleware;
use Careminate\Http\Middleware\ProblemExceptionHandler;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\Router;
use Careminate\Http\Runtime\HttpRunner;
use Careminate\Http\ServerRequestFactory;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JsonApiEndToEndIntegrationTest extends TestCase
{
    /**
     * @return iterable<string, array{
     *     string,
     *     string,
     *     array<array-key, mixed>
     * }>
     */
    public static function validJsonCases(): iterable
    {
        yield 'basic object'            => [
            '{"name":"Careminate"}',
            'application/json',
            ['name' => 'Careminate'],
        ];

        yield 'empty object'            => [
            '{}',
            'application/json',
            [],
        ];

        yield 'nested object'           => [
            '{"meta":{"enabled":true}}',
            'application/json',
            ['meta' => ['enabled' => true]],
        ];

        yield 'array property'          => [
            '{"ids":[1,2,3]}',
            'application/json',
            ['ids' => [1, 2, 3]],
        ];

        yield 'Unicode'                 => [
            '{"title":"مرحبا"}',
            'application/json',
            ['title' => 'مرحبا'],
        ];

        yield 'vendor JSON suffix'      => [
            '{"ok":true}',
            'application/vnd.api+json',
            ['ok' => true],
        ];

        yield 'UTF-8 charset'           => [
            '{"ok":true}',
            'application/json; charset=utf-8',
            ['ok' => true],
        ];

        yield 'large integer preserved' => [
            '{"id":9223372036854775808}',
            'application/json',
            ['id' => '9223372036854775808'],
        ];
    }

    /**
     * @param array<array-key, mixed> $expected
     */
    #[DataProvider('validJsonCases')]
    public function testValidJsonSurvivesIngressParsingRoutingAndEmission(
        string $body,
        string $contentType,
        array $expected,
    ): void {
        $kernel = self::apiKernel();

        [$response, $output] = self::send(
            $kernel,
            'POST',
            '/api/records?source=integration',
            $body,
            [
                'CONTENT_TYPE'   => $contentType,
                'CONTENT_LENGTH' => (string) strlen($body),
            ],
        );

        self::assertSame(201, $response->status);
        self::assertSame(201, $output->status());

        self::assertSame(
            'application/json; charset=utf-8',
            $output->headers()['content-type'] ?? null,
        );

        self::assertSame(
            'POST',
            $output->headers()['x-request-method'] ?? null,
        );

        self::assertSame(
            $response->body,
            $output->body(),
        );

        self::assertSame(
            ['received' => $expected],
            json_decode(
                $output->body(),
                true,
                flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            ),
        );
    }

    /**
     * @return iterable<string, array{
     *     string,
     *     ?string,
     *     int,
     *     int,
     *     string
     * }>
     */
    public static function invalidJsonCases(): iterable
    {
        yield 'malformed syntax'            => [
            '{"a":}',
            'application/json',
            400,
            32,
            'Bad Request',
        ];

        yield 'trailing comma'              => [
            '{"a":1,}',
            'application/json',
            400,
            32,
            'Bad Request',
        ];

        yield 'array root'                  => [
            '[1,2]',
            'application/json',
            400,
            32,
            'Bad Request',
        ];

        yield 'scalar root'                 => [
            'true',
            'application/json',
            400,
            32,
            'Bad Request',
        ];

        yield 'empty body'                  => [
            '',
            'application/json',
            400,
            32,
            'Bad Request',
        ];

        yield 'duplicate root key'          => [
            '{"a":1,"a":2}',
            'application/json',
            400,
            32,
            'Bad Request',
        ];

        yield 'escaped equivalent key'      => [
            '{"name":1,"\\u006eame":2}',
            'application/json',
            400,
            32,
            'Bad Request',
        ];

        yield 'nested duplicate'            => [
            '{"x":{"id":1,"id":2}}',
            'application/json',
            400,
            32,
            'Bad Request',
        ];

        yield 'unsupported text media type' => [
            '{"a":1}',
            'text/plain',
            415,
            32,
            'Unsupported Media Type',
        ];

        yield 'unsupported charset'         => [
            '{"a":1}',
            'application/json; charset=latin1',
            415,
            32,
            'Unsupported Media Type',
        ];

        yield 'missing content type'        => [
            '{"a":1}',
            null,
            415,
            32,
            'Unsupported Media Type',
        ];

        yield 'parser byte limit'           => [
            '{"name":"too-long"}',
            'application/json',
            413,
            8,
            'Content Too Large',
        ];
    }

    #[DataProvider('invalidJsonCases')]
    public function testParserFailuresBecomeSanitizedProblemResponses(
        string $body,
        ?string $contentType,
        int $status,
        int $parserLimit,
        string $title,
    ): void {
        $calls = 0;

        $kernel = self::apiKernel(
            $parserLimit,
            64,
            $calls,
        );

        $extra = [
            'CONTENT_LENGTH' => (string) strlen($body),
        ];

        if ($contentType !== null) {
            $extra['CONTENT_TYPE'] = $contentType;
        }

        [$response, $output] = self::send(
            $kernel,
            'POST',
            '/api/records',
            $body,
            $extra,
        );

        self::assertSame($status, $response->status);
        self::assertSame($status, $output->status());

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
                'type'   => 'about:blank',
                'title'  => $title,
                'status' => $status,
            ],
            json_decode(
                $output->body(),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );

        self::assertSame(1, $calls);

        self::assertStringNotContainsString(
            'too-long',
            $output->body(),
        );

        self::assertStringNotContainsString(
            'name',
            $output->body(),
        );
    }

    /**
     * @return iterable<string, array{
     *     array<string, mixed>,
     *     string,
     *     int,
     *     int
     * }>
     */
    public static function invalidIngressCases(): iterable
    {
        yield 'Content-Length mismatch'       => [
            ['CONTENT_LENGTH' => '4'],
            'abc',
            32,
            400,
        ];

        yield 'negative length'               => [
            ['CONTENT_LENGTH' => '-1'],
            '',
            32,
            400,
        ];

        yield 'conflicting length aliases'    => [
            [
                'CONTENT_LENGTH'      => '3',
                'HTTP_CONTENT_LENGTH' => '2',
            ],
            'abc',
            32,
            400,
        ];

        yield 'length plus transfer encoding' => [
            [
                'CONTENT_LENGTH'         => '3',
                'HTTP_TRANSFER_ENCODING' => 'chunked',
            ],
            'abc',
            32,
            400,
        ];

        yield 'invalid HTTP method'           => [
            ['REQUEST_METHOD' => 'PO ST'],
            '',
            32,
            400,
        ];

        yield 'invalid target'                => [
            ['REQUEST_URI' => '/api/records#fragment'],
            '',
            32,
            400,
        ];

        yield 'actual body too large'         => [
            [],
            str_repeat('x', 33),
            32,
            413,
        ];

        yield 'declared body too large'       => [
            ['CONTENT_LENGTH' => '33'],
            '',
            32,
            413,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidIngressCases')]
    public function testBadIngressNeverInvokesKernelOrLeaksDetails(
        array $overrides,
        string $body,
        int $limit,
        int $status,
    ): void {
        $calls = 0;

        $kernel = self::apiKernel(
            1048576,
            64,
            $calls,
        );

        [$response, $output] = self::send(
            $kernel,
            'POST',
            '/api/records',
            $body,
            $overrides,
            $limit,
        );

        self::assertSame($status, $response->status);
        self::assertSame($status, $output->status());
        self::assertSame(0, $calls);

        self::assertFalse(
            $kernel->router()->isFrozen(),
        );

        self::assertSame(
            'application/problem+json',
            $response->header('Content-Type'),
        );

        self::assertSame(
            $response->body,
            $output->body(),
        );

        $problem = json_decode(
            $response->body,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($problem);

        self::assertArrayHasKey(
            'status',
            $problem,
        );

        self::assertSame(
            $status,
            $problem['status'],
        );

    }

    /**
     * @return iterable<string, array{
     *     string,
     *     string,
     *     int,
     *     ?string,
     *     string
     * }>
     */
    public static function methodCases(): iterable
    {
        yield 'GET existing dynamic record'  => [
            'GET',
            '/api/records/42',
            200,
            null,
            '{"id":"42"}',
        ];

        yield 'HEAD existing dynamic record' => [
            'HEAD',
            '/api/records/42',
            200,
            null,
            '',
        ];

        yield 'OPTIONS dynamic record'       => [
            'OPTIONS',
            '/api/records/42',
            204,
            'GET, HEAD, OPTIONS',
            '',
        ];

        yield 'OPTIONS POST-only collection' => [
            'OPTIONS',
            '/api/records',
            204,
            'OPTIONS, POST',
            '',
        ];

        yield 'DELETE POST-only collection'  => [
            'DELETE',
            '/api/records',
            405,
            'OPTIONS, POST',
            'Method Not Allowed',
        ];

        yield 'PATCH GET-only record'        => [
            'PATCH',
            '/api/records/42',
            405,
            'GET, HEAD, OPTIONS',
            'Method Not Allowed',
        ];

        yield 'GET missing path'             => [
            'GET',
            '/api/missing',
            404,
            null,
            'Not Found',
        ];

        yield 'HEAD missing path'            => [
            'HEAD',
            '/api/missing',
            404,
            null,
            '',
        ];

        yield 'explicit OPTIONS'             => [
            'OPTIONS',
            '/api/manual-options',
            200,
            null,
            'manual',
        ];
    }

    #[DataProvider('methodCases')]
    public function testMethodSemanticsRemainCorrectThroughEmitter(
        string $method,
        string $target,
        int $status,
        ?string $allow,
        string $body,
    ): void {
        [$response, $output] = self::send(
            self::apiKernel(),
            $method,
            $target,
        );

        self::assertSame($status, $response->status);
        self::assertSame($status, $output->status());

        self::assertSame(
            $allow,
            $output->headers()['allow'] ?? null,
        );

        self::assertSame($body, $response->body);
        self::assertSame($body, $output->body());
    }

    public function testParserRejectsExcessiveNestingThroughProblemMiddleware(): void
    {
        $kernel = self::apiKernel(maxDepth: 2);

        [$response, $output] = self::send(
            $kernel,
            'POST',
            '/api/records',
            '{"a":{"b":{"c":1}}}',
            ['CONTENT_TYPE' => 'application/json'],
        );

        self::assertSame(400, $response->status);

        self::assertSame(
            'application/problem+json',
            $output->headers()['content-type'] ?? null,
        );

        self::assertSame(
            'no-store',
            $response->header('Cache-Control'),
        );
    }

    public function testParserFailureDoesNotPoisonLaterRequestOnSameKernel(): void
    {
        $kernel = self::apiKernel();

        [$invalid] = self::send(
            $kernel,
            'POST',
            '/api/records',
            '{"id":1,"id":2}',
            ['CONTENT_TYPE' => 'application/json'],
        );

        [$valid, $output] = self::send(
            $kernel,
            'POST',
            '/api/records',
            '{"id":3}',
            ['CONTENT_TYPE' => 'application/json'],
        );

        self::assertSame(400, $invalid->status);
        self::assertSame(201, $valid->status);

        self::assertSame(
            ['received' => ['id' => 3]],
            json_decode(
                $output->body(),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testInvalidIngressDoesNotBootKernelAndLaterRequestWorks(): void
    {
        $kernel = self::apiKernel();

        [$invalid] = self::send(
            $kernel,
            'POST',
            '/api/records',
            'x',
            ['CONTENT_LENGTH' => '4'],
        );

        self::assertSame(400, $invalid->status);

        self::assertFalse(
            $kernel->router()->isFrozen(),
        );

        [$valid] = self::send(
            $kernel,
            'POST',
            '/api/records',
            '{}',
            [
                'CONTENT_TYPE'   => 'application/json',
                'CONTENT_LENGTH' => '2',
            ],
        );

        self::assertSame(201, $valid->status);

        self::assertTrue(
            $kernel->router()->isFrozen(),
        );
    }

    public function testSuccessFailureAndDynamicParametersStayIsolatedAcrossRequests(): void
    {
        $kernel = self::apiKernel();

        for ($i = 1; $i <= 12; ++$i) {
            [$detail] = self::send(
                $kernel,
                'GET',
                '/api/records/' . $i,
            );

            [$invalid] = self::send(
                $kernel,
                'POST',
                '/api/records',
                '{"k":1,"k":2}',
                ['CONTENT_TYPE' => 'application/json'],
            );

            [$health] = self::send(
                $kernel,
                'GET',
                '/api/health',
            );

            self::assertSame(
                ['id' => (string) $i],
                json_decode(
                    $detail->body,
                    true,
                    flags: JSON_THROW_ON_ERROR,
                ),
            );

            self::assertSame(400, $invalid->status);
            self::assertSame('healthy', $health->body);

            self::assertSame(
                '0',
                $health->header('X-Route-Parameter-Count'),
            );
        }
    }

    public function testUnexpectedHandlerExceptionIsSanitizedAndKernelRecovers(): void
    {
        $kernel = self::apiKernel();

        [$failed, $output] = self::send(
            $kernel,
            'GET',
            '/api/crash',
        );

        [$healthy] = self::send(
            $kernel,
            'GET',
            '/api/health',
        );

        self::assertSame(500, $failed->status);

        self::assertSame(
            'application/problem+json',
            $output->headers()['content-type'] ?? null,
        );

        self::assertStringNotContainsString(
            'internal-secret',
            $output->body(),
        );

        self::assertSame('healthy', $healthy->body);
    }

    public function testHeadParserExceptionProducesBodylessProblemResponse(): void
    {
        $kernel = self::apiKernel();

        [$response, $output] = self::send(
            $kernel,
            'HEAD',
            '/api/parser-error',
        );

        self::assertSame(415, $response->status);

        self::assertSame(
            'application/problem+json',
            $response->header('Content-Type'),
        );

        self::assertSame('', $response->body);
        self::assertSame('', $output->body());
    }

    public function testExistingRunnerRetainsUncaughtIngressExceptionContract(): void
    {
        $kernel = self::apiKernel();

        $output = new BufferedResponseOutput();

        $runner = new HttpRunner(
            new ServerRequestFactory(),
            $kernel,
            new ResponseEmitter($output),
        );

        try {
            $runner->run(
                [
                    'REQUEST_METHOD' => 'POST',
                    'REQUEST_URI'    => '/api/records',
                    'CONTENT_LENGTH' => '9',
                ],
                'short',
            );

            self::fail(
                'Expected invalid Content-Length to be rejected.',
            );
        } catch (InvalidArgumentException) {
            self::assertNull($output->status());
            self::assertSame('', $output->body());

            self::assertFalse(
                $kernel->router()->isFrozen(),
            );
        }
    }

    public function testAutomaticOptionsSkipsGroupMiddlewareButPostRunsIt(): void
    {
        $events = [];

        $record = static function (string $event) use (&$events): void {
            $events[] = $event;
        };

        $middleware = new class ($record) implements Middleware {
            /**
             * @param Closure(string): void $record
             */
            public function __construct(
                private readonly Closure $record,
            ) {
            }

            /**
             * @param Closure(Request): Response $next
             */
            public function process(
                Request $request,
                Closure $next,
            ): Response {
                ($this->record)('before');

                $response = $next($request);

                ($this->record)('after');

                return $response;
            }
        };

        $kernel = self::apiKernel(
            groupMiddleware: [$middleware],
        );

        [$options] = self::send(
            $kernel,
            'OPTIONS',
            '/api/records',
        );

        [$post] = self::send(
            $kernel,
            'POST',
            '/api/records',
            '{}',
            ['CONTENT_TYPE' => 'application/json'],
        );

        self::assertSame(204, $options->status);
        self::assertSame(201, $post->status);

        self::assertSame(
            ['before', 'after'],
            $events,
        );
    }

    public function testJsonResponseDoesNotGrantCorsAutomatically(): void
    {
        [, $output] = self::send(
            self::apiKernel(),
            'OPTIONS',
            '/api/records',
            '',
            [
                'HTTP_ORIGIN'                        => 'https://other.example',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            ],
        );

        self::assertSame(204, $output->status());

        self::assertArrayNotHasKey(
            'access-control-allow-origin',
            $output->headers(),
        );

        self::assertArrayNotHasKey(
            'access-control-allow-methods',
            $output->headers(),
        );
    }

    /**
     * @param list<Middleware> $groupMiddleware
     */
    private static function apiKernel(
        int $parserLimit = 1048576,
        int $maxDepth = 64,
        ?int &$parseCalls = null,
        array $groupMiddleware = [],
    ): Kernel {
        $kernel = new Kernel(new Application());

        $kernel->middleware(
            new ProblemExceptionHandler(),
        );

        $parser = new JsonBodyParser(
            $parserLimit,
            $maxDepth,
        );

        $kernel->router()->group(
            '/api',
            static function (
                Router $router,
            ) use ($parser, &$parseCalls): void {
                $router->post(
                    '/records',
                    static function (
                        Request $request,
                    ) use ($parser, &$parseCalls): Response {
                        if ($parseCalls !== null) {
                            ++$parseCalls;
                        }

                        $value = $parser->parseObject(
                            $request,
                        );

                        return new Response(
                            201,
                            [
                                'Content-Type'     =>
                                'application/json; charset=utf-8',
                                'X-Request-Method' => $request->method,
                            ],
                            json_encode(
                                ['received' => $value],
                                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                            ),
                        );
                    },
                );

                $router->get(
                    '/records/{id}',
                    static fn (Request $request): Response =>
                    new Response(
                        200,
                        ['Content-Type' => 'application/json'],
                        json_encode(
                            [
                                'id' =>
                                $request->routeParameter('id'),
                            ],
                            JSON_THROW_ON_ERROR,
                        ),
                    ),
                    ['id' => '[0-9]+'],
                );

                $router->get(
                    '/health',
                    static fn (Request $request): Response =>
                    new Response(
                        200,
                        [
                            'X-Route-Parameter-Count' =>
                            (string) count(
                                $request->routeParameters(),
                            ),
                        ],
                        'healthy',
                    ),
                );

                $router->add(
                    'OPTIONS',
                    '/manual-options',
                    static fn (Request $request): Response =>
                    new Response(200, [], 'manual'),
                );

                $router->get(
                    '/crash',
                    static function (Request $request): Response {
                        throw new RuntimeException(
                            'internal-secret',
                        );
                    },
                );

                $router->get(
                    '/parser-error',
                    static function (Request $request): Response {
                        throw new HttpException(
                            415,
                            'private parser metadata',
                        );
                    },
                );
            },
            $groupMiddleware,
        );

        return $kernel;
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array{Response, BufferedResponseOutput}
     */
    private static function send(
        Kernel $kernel,
        string $method,
        string $target,
        string $body = '',
        array $headers = [],
        int $ingressLimit = 1048576,
    ): array {
        $server = array_replace(
            [
                'REQUEST_METHOD' => $method,
                'REQUEST_URI'    => $target,
            ],
            $headers,
        );

        $output = new BufferedResponseOutput();

        // HttpRunner is one-shot. Each simulated HTTP request
        // gets a new runner and output buffer, while the same
        // kernel can be reused to verify request isolation.
        $runner = new HttpRunner(
            new ServerRequestFactory($ingressLimit),
            $kernel,
            new ResponseEmitter($output),
        );

        return [
            $runner->runSafely($server, $body),
            $output,
        ];
    }
}
