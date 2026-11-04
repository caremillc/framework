<?php

declare (strict_types=1);

namespace Careminate\Tests\Integration;

use Careminate\Foundation\Application;
use Careminate\Http\Emission\BufferedResponseOutput;
use Careminate\Http\Emission\ResponseEmitter;
use Careminate\Http\Kernel;
use Careminate\Http\Middleware\Middleware;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Routing\Router;
use Careminate\Http\Runtime\HttpRunner;
use Careminate\Http\ServerRequestFactory;
use Closure;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HttpBoundarySecurityMatrixTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, string, int}>
     */
    public static function invalidInboundCases(): iterable
    {
        yield 'missing method'                     => [['REQUEST_METHOD' => null], '', 4];
        yield 'numeric method'                     => [['REQUEST_METHOD' => 123], '', 4];
        yield 'empty method'                       => [['REQUEST_METHOD' => ''], '', 4];
        yield 'method with spaces'                 => [['REQUEST_METHOD' => 'GET POST'], '', 4];
        yield 'method with newline'                => [['REQUEST_METHOD' => "GET\n"], '', 4];
        yield 'missing target'                     => [['REQUEST_URI' => null], '', 4];
        yield 'empty target'                       => [['REQUEST_URI' => ''], '', 4];
        yield 'relative target'                    => [['REQUEST_URI' => 'safe'], '', 4];
        yield 'absolute-form target'               => [['REQUEST_URI' => 'https://example.test/safe'], '', 4];
        yield 'space in target'                    => [['REQUEST_URI' => '/sa fe'], '', 4];
        yield 'fragment in target'                 => [['REQUEST_URI' => '/safe#fragment'], '', 4];
        yield 'newline in target'                  => [['REQUEST_URI' => "/safe\n"], '', 4];
        yield 'response splitting in header'       => [['HTTP_X_TAG' => "ok\r\nX-Injected: yes"], '', 4];
        yield 'header value is array'              => [['HTTP_ACCEPT' => ['text/plain']], '', 4];
        yield 'empty header source name'           => [['HTTP_' => 'value'], '', 4];

        yield 'conflicting content-type aliases'   => [
            [
                'CONTENT_TYPE'      => 'application/json',
                'HTTP_CONTENT_TYPE' => 'text/plain',
            ],
            '',
            4,
        ];

        yield 'conflicting content-length aliases' => [
            [
                'CONTENT_LENGTH'      => '4',
                'HTTP_CONTENT_LENGTH' => '5',
            ],
            '',
            4,
        ];

        yield 'body above limit'                   => [[], '12345', 4];
        yield 'body forbidden by zero limit'       => [[], 'x', 0];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidInboundCases')]
    public function testInvalidInboundRequestCannotReachKernelOrEmitter(
        array $overrides,
        string $body,
        int $maxBodyBytes,
    ): void {
        $kernel = new Kernel(new Application());
        $calls  = 0;

        $kernel->router()->get(
            '/safe',
            static function (Request $request) use (&$calls): Response {
                ++$calls;

                return new Response(200, [], 'must not run');
            },
        );

        $output = new BufferedResponseOutput();
        $runner = self::runner($kernel, $output, $maxBodyBytes);
        $server = array_replace(
            self::server('GET', '/safe'),
            $overrides,
        );

        try {
            $runner->run($server, $body);
            self::fail('Expected invalid inbound request to be rejected.');
        } catch (InvalidArgumentException) {
            // Validation must happen before application dispatch.
        }

        self::assertSame(0, $calls);
        self::assertNull($output->status());
        self::assertSame([], $output->headers());
        self::assertSame('', $output->body());
        self::assertTrue($runner->hasExecuted());
        self::assertFalse($kernel->router()->isFrozen());

        $this->expectException(LogicException::class);

        $runner->run(self::server('GET', '/safe'));
    }

    /**
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function acceptedHeaderCases(): iterable
    {
        yield 'content type standard source'     => [
            ['CONTENT_TYPE' => 'application/json'],
            'Content-Type',
            'application/json',
        ];

        yield 'content type HTTP source'         => [
            ['HTTP_CONTENT_TYPE' => 'application/json'],
            'Content-Type',
            'application/json',
        ];

        yield 'identical content type aliases'   => [
            [
                'CONTENT_TYPE'      => 'application/json',
                'HTTP_CONTENT_TYPE' => 'application/json',
            ],
            'Content-Type',
            'application/json',
        ];

        yield 'identical content length aliases' => [
            [
                'CONTENT_LENGTH'      => '4',
                'HTTP_CONTENT_LENGTH' => '4',
            ],
            'Content-Length',
            '4',
        ];

        yield 'custom header normalization'      => [
            ['HTTP_X_CORRELATION_ID' => 'trace-123'],
            'X-Correlation-Id',
            'trace-123',
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('acceptedHeaderCases')]
    public function testAcceptedHeaderSourcesNormalizeWithoutDataLoss(
        array $headers,
        string $name,
        string $expected,
    ): void {
        // Content-Length must match the actual request body.
        // The data provider declares 4 bytes for this case.
        $body = $name === 'Content-Length'
            ? 'test'
            : '';

        $request = new ServerRequestFactory()->fromServer(
            array_replace(
                self::server('GET', '/safe'),
                $headers,
            ),
            $body,
        );

        self::assertSame(
            $expected,
            $request->header($name),
        );

        self::assertSame(
            $expected,
            $request->header(strtolower($name)),
        );

        self::assertSame(
            $body,
            $request->body,
        );
    }

    /**
     * @return iterable<string, array{
     *     list<string>,
     *     string,
     *     int,
     *     ?string,
     *     ?string,
     *     string
     * }>
     */
    public static function emittedMethodCases(): iterable
    {
        yield 'GET success'                 => [
            ['GET'], 'GET', 200, null, 'GET', 'GET',
        ];

        yield 'lowercase GET'               => [
            ['GET'], 'get', 200, null, 'GET', 'GET',
        ];

        yield 'HEAD fallback'               => [
            ['GET'], 'HEAD', 200, null, 'GET', '',
        ];

        yield 'lowercase HEAD fallback'     => [
            ['GET'], 'head', 200, null, 'GET', '',
        ];

        yield 'automatic OPTIONS'           => [
            ['GET'], 'OPTIONS', 204, 'GET, HEAD, OPTIONS', null, '',
        ];

        yield 'GET route POST mismatch'     => [
            ['GET'], 'POST', 405,
            'GET, HEAD, OPTIONS', null, 'Method Not Allowed',
        ];

        yield 'GET route DELETE mismatch'   => [
            ['GET'], 'DELETE', 405,
            'GET, HEAD, OPTIONS', null, 'Method Not Allowed',
        ];

        yield 'POST success'                => [
            ['POST'], 'POST', 200, null, 'POST', 'POST',
        ];

        yield 'POST route HEAD mismatch'    => [
            ['POST'], 'HEAD', 405, 'OPTIONS, POST', null, '',
        ];

        yield 'POST route OPTIONS'          => [
            ['POST'], 'OPTIONS', 204, 'OPTIONS, POST', null, '',
        ];

        yield 'explicit HEAD'               => [
            ['HEAD'], 'HEAD', 200, null, 'HEAD', '',
        ];

        yield 'HEAD-only GET mismatch'      => [
            ['HEAD'], 'GET', 405,
            'HEAD, OPTIONS', null, 'Method Not Allowed',
        ];

        yield 'explicit OPTIONS'            => [
            ['OPTIONS'], 'OPTIONS', 200, null, 'OPTIONS', 'OPTIONS',
        ];

        yield 'GET and POST OPTIONS'        => [
            ['GET', 'POST'], 'OPTIONS', 204,
            'GET, HEAD, OPTIONS, POST', null, '',
        ];

        yield 'GET and POST PATCH mismatch' => [
            ['GET', 'POST'], 'PATCH', 405,
            'GET, HEAD, OPTIONS, POST', null, 'Method Not Allowed',
        ];

        yield 'unknown GET path'            => [
            [], 'GET', 404, null, null, 'Not Found',
        ];

        yield 'unknown HEAD path'           => [
            [], 'HEAD', 404, null, null, '',
        ];

        yield 'unknown OPTIONS path'        => [
            [], 'OPTIONS', 404, null, null, 'Not Found',
        ];
    }

    /**
     * @param list<string> $registeredMethods
     */
    #[DataProvider('emittedMethodCases')]
    public function testMethodContractReachesEmissionBoundary(
        array $registeredMethods,
        string $requestMethod,
        int $expectedStatus,
        ?string $expectedAllow,
        ?string $expectedHandler,
        string $expectedBody,
    ): void {
        $kernel = new Kernel(new Application());

        foreach ($registeredMethods as $method) {
            $kernel->router()->add(
                $method,
                '/resource',
                static fn (Request $request): Response =>
                new Response(
                    200,
                    [
                        'X-Handler'        => $method,
                        'X-Request-Method' => $request->method,
                    ],
                    $method,
                ),
            );
        }

        $output = new BufferedResponseOutput();

        $response = self::runner($kernel, $output)->run(
            self::server(
                $requestMethod,
                '/resource?from=matrix',
            ),
        );

        self::assertSame($expectedStatus, $response->status);
        self::assertSame($expectedStatus, $output->status());

        self::assertSame(
            $expectedAllow,
            $output->headers()['allow'] ?? null,
        );

        self::assertSame(
            $expectedHandler,
            $output->headers()['x-handler'] ?? null,
        );

        self::assertSame($expectedBody, $response->body);
        self::assertSame($expectedBody, $output->body());

        if ($expectedHandler !== null) {
            self::assertSame(
                strtoupper($requestMethod),
                $output->headers()['x-request-method'] ?? null,
            );
        }
    }

    public function testBinaryBodyExactlyAtConfiguredLimitSurvivesRoundTrip(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->post(
            '/binary',
            static fn (Request $request): Response =>
            new Response(
                200,
                ['Content-Type' => 'application/octet-stream'],
                $request->body,
            ),
        );

        $output = new BufferedResponseOutput();
        $binary = "\x00a\xFFb";

        $response = self::runner($kernel, $output, 4)->run(
            self::server('POST', '/binary'),
            $binary,
        );

        self::assertSame(200, $response->status);
        self::assertSame($binary, $response->body);
        self::assertSame($binary, $output->body());

        self::assertSame(
            'application/octet-stream',
            $output->headers()['content-type'] ?? null,
        );
    }

    public function testOverlappingExactAndDynamicMethodsProduceCorrectEmittedAllow(): void
    {
        $kernel = new Kernel(new Application());
        $router = $kernel->router();

        $router->get(
            '/records/active',
            self::handler('exact-get'),
        );

        $router->post(
            '/records/{slug}',
            self::handler('dynamic-post'),
        );

        $router->add(
            'PATCH',
            '/records/{id}',
            self::handler('numeric-patch'),
            ['id' => '[0-9]+'],
        );

        $activeOutput = new BufferedResponseOutput();

        self::runner($kernel, $activeOutput)->run(
            self::server('OPTIONS', '/records/active'),
        );

        self::assertSame(204, $activeOutput->status());

        self::assertSame(
            'GET, HEAD, OPTIONS, POST',
            $activeOutput->headers()['allow'] ?? null,
        );

        self::assertSame('', $activeOutput->body());

        $numericOutput = new BufferedResponseOutput();

        self::runner($kernel, $numericOutput)->run(
            self::server('OPTIONS', '/records/42'),
        );

        self::assertSame(204, $numericOutput->status());

        self::assertSame(
            'OPTIONS, PATCH, POST',
            $numericOutput->headers()['allow'] ?? null,
        );

        self::assertSame('', $numericOutput->body());
    }

    public function testAutomaticOptionsHasNoImplicitCorsPolicy(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/resource',
            self::handler('get'),
        );

        $output = new BufferedResponseOutput();

        self::runner($kernel, $output)->run([
            'REQUEST_METHOD'                     => 'OPTIONS',
            'REQUEST_URI'                        => '/resource',
            'HTTP_ORIGIN'                        => 'https://untrusted.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        // self::assertSame(204, $output->status);
        self::assertSame(204, $output->status());

        self::assertSame(
            'GET, HEAD, OPTIONS',
            $output->headers()['allow'] ?? null,
        );

        self::assertArrayNotHasKey(
            'access-control-allow-origin',
            $output->headers(),
        );

        self::assertArrayNotHasKey(
            'access-control-allow-methods',
            $output->headers(),
        );
    }

    public function testAutomaticOptionsRunsGlobalMiddlewareButNotRouteGroupMiddleware(): void
    {
        $kernel = new Kernel(new Application());
        $events = [];

        $groupMiddleware = self::recorder($events, 'group');

        $kernel->router()->group(
            '/api',
            static function (Router $router): void {
                $router->get(
                    '/resource',
                    self::handler('get'),
                );
            },
            [$groupMiddleware],
        );

        $kernel->closureMiddleware(
            /**
             * @param Closure(Request): Response $next
             */
            static function (
                Request $request,
                Closure $next,
            ) use (&$events): Response {
                $events[] = 'global-before';

                /** @var Response $response */
                $response = $next($request);

                $events[] = 'global-after';

                return $response->withHeader(
                    'X-Global',
                    'yes',
                );
            },
        );

        $output = new BufferedResponseOutput();

        self::runner($kernel, $output)->run(
            self::server('OPTIONS', '/api/resource'),
        );

        self::assertSame(
            ['global-before', 'global-after'],
            $events,
        );

        self::assertSame(204, $output->status());

        self::assertSame(
            'yes',
            $output->headers()['x-global'] ?? null,
        );

        self::assertSame('', $output->body());
    }

    public function testExplicitOptionsRunsAssignedGroupMiddleware(): void
    {
        $kernel = new Kernel(new Application());
        $events = [];

        $middleware = self::recorder($events, 'group');

        $kernel->router()->group(
            '/api',
            static function (Router $router): void {
                $router->get(
                    '/resource',
                    self::handler('get'),
                );

                $router->add(
                    'OPTIONS',
                    '/resource',
                    self::handler('explicit-options'),
                );
            },
            [$middleware],
        );

        $output = new BufferedResponseOutput();

        self::runner($kernel, $output)->run(
            self::server('OPTIONS', '/api/resource'),
        );

        self::assertSame(
            ['group-before', 'group-after'],
            $events,
        );

        self::assertSame(200, $output->status());
        self::assertSame('explicit-options', $output->body());
    }

    public function testGlobalShortCircuitHeadCannotEmitAResponseBody(): void
    {
        $kernel = new Kernel(new Application());
        $calls  = 0;

        $kernel->router()->get(
            '/private',
            static function (Request $request) use (&$calls): Response {
                ++$calls;

                return new Response(200, [], 'private');
            },
        );

        $kernel->closureMiddleware(
            /**
             * @param Closure(Request): Response $next
             */
            static fn (
                Request $request,
                Closure $next,
            ): Response => new Response(
                403,
                ['X-Denied' => 'yes'],
                'access denied',
            ),
        );

        $output = new BufferedResponseOutput();

        $response = self::runner($kernel, $output)->run(
            self::server('HEAD', '/private'),
        );

        self::assertSame(0, $calls);
        self::assertSame(403, $response->status);
        self::assertSame('', $response->body);

        self::assertSame(
            'yes',
            $output->headers()['x-denied'] ?? null,
        );

        self::assertSame('', $output->body());
    }

    public function testInvalidResponseHeaderPreventsAnyEmission(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/unsafe',
            static fn (Request $request): Response =>
            new Response(
                200,
                ['X-Unsafe' => "safe\r\nInjected: yes"],
                'body',
            ),
        );

        $output = new BufferedResponseOutput();
        $runner = self::runner($kernel, $output);

        try {
            $runner->run(
                self::server('GET', '/unsafe'),
            );

            self::fail('Expected unsafe response header rejection.');
        } catch (InvalidArgumentException) {
            // Response construction rejects CRLF before emission.
        }

        self::assertNull($output->status());
        self::assertSame([], $output->headers());
        self::assertSame('', $output->body());
        self::assertTrue($runner->hasExecuted());
    }

    public function testRunnerCannotBeReusedAfterSuccessfulEmission(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/once',
            self::handler('once'),
        );

        $output = new BufferedResponseOutput();
        $runner = self::runner($kernel, $output);

        self::assertSame(
            200,
            $runner->run(
                self::server('GET', '/once'),
            )->status,
        );

        self::assertSame('once', $output->body());
        self::assertTrue($runner->hasExecuted());

        $this->expectException(LogicException::class);

        $runner->run(self::server('GET', '/once'));
    }

    public function testRepeatedKernelRequestsDoNotLeakParametersOrBodies(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/users/{id}',
            static fn (Request $request): Response =>
            new Response(
                200,
                [
                    'X-Id' =>
                    $request->routeParameter('id') ?? '',
                ],
                'user',
            ),
        );

        $kernel->router()->get(
            '/health',
            static fn (Request $request): Response =>
            new Response(
                200,
                [
                    'X-Param-Count' => (string) count(
                        $request->routeParameters(),
                    ),
                ],
                'healthy',
            ),
        );

        for ($i = 0; $i < 20; ++$i) {
            $id = (string) $i;

            $user = $kernel->handle(
                new Request('GET', '/users/' . $id),
            );

            $head = $kernel->handle(
                new Request('HEAD', '/users/' . $id),
            );

            $health = $kernel->handle(
                new Request('GET', '/health'),
            );

            $mismatch = $kernel->handle(
                new Request('DELETE', '/users/' . $id),
            );

            $missing = $kernel->handle(
                new Request('GET', '/unknown'),
            );

            self::assertSame($id, $user->header('X-Id'));
            self::assertSame('user', $user->body);

            self::assertSame($id, $head->header('X-Id'));
            self::assertSame('', $head->body);

            self::assertSame(
                '0',
                $health->header('X-Param-Count'),
            );

            self::assertSame('healthy', $health->body);
            self::assertSame(405, $mismatch->status);

            self::assertSame(
                'GET, HEAD, OPTIONS',
                $mismatch->header('Allow'),
            );

            self::assertSame(404, $missing->status);
            self::assertNull($missing->header('Allow'));
        }

        self::assertSame(2, $kernel->router()->count());
        self::assertTrue($kernel->router()->isFrozen());
    }

    public function testHandlerExceptionDoesNotPoisonLaterKernelRequests(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/fail',
            static function (Request $request): Response {
                throw new RuntimeException(
                    'expected handler failure',
                );
            },
        );

        $kernel->router()->get(
            '/ok',
            self::handler('recovered'),
        );

        try {
            $kernel->handle(
                new Request('GET', '/fail'),
            );

            self::fail('Expected handler exception.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'expected handler failure',
                $exception->getMessage(),
            );
        }

        $response = $kernel->handle(
            new Request('GET', '/ok'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('recovered', $response->body);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function server(
        string $method,
        string $uri,
        array $extra = [],
    ): array {
        return array_replace(
            [
                'REQUEST_METHOD' => $method,
                'REQUEST_URI'    => $uri,
            ],
            $extra,
        );
    }

    private static function runner(
        Kernel $kernel,
        BufferedResponseOutput $output,
        int $maxBodyBytes = 1048576,
    ): HttpRunner {
        return new HttpRunner(
            new ServerRequestFactory($maxBodyBytes),
            $kernel,
            new ResponseEmitter($output),
        );
    }

    /**
     * @return Closure(Request): Response
     */
    private static function handler(string $body): Closure
    {
        return static fn (Request $request): Response =>
        new Response(200, [], $body);
    }

    /**
     * @param list<string> $events
     */
    private static function recorder(
        array &$events,
        string $name,
    ): Middleware {
        $record = static function (
            string $event,
        ) use (&$events): void {
            $events[] = $event;
        };

        return new class ($record, $name) implements Middleware {
            /**
             * @param Closure(string): void $record
             */
            public function __construct(
                private readonly Closure $record,
                private readonly string $name,
            ) {
            }

            /**
             * @param Closure(Request): Response $next
             */
            public function process(
                Request $request,
                Closure $next,
            ): Response {
                ($this->record)(
                    $this->name . '-before',
                );

                $response = $next($request);

                ($this->record)(
                    $this->name . '-after',
                );

                return $response;
            }
        };
    }
}
