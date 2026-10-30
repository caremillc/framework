<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http;

use Careminate\Foundation\Application;
use Careminate\Http\Kernel;
use Careminate\Http\Request;
use Careminate\Http\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HttpBoundaryHardeningTest extends TestCase
{
    public function testHttpMethodsAreNormalizedToUppercase(): void
    {
        foreach ([
            'get' => 'GET',
            'post' => 'POST',
            'patch' => 'PATCH',
            'delete' => 'DELETE',
            'options' => 'OPTIONS',
            'head' => 'HEAD',
        ] as $input => $expected) {
            $request = new Request($input, '/');

            self::assertSame($expected, $request->method);
        }
    }

    public function testInvalidHttpMethodTokensAreRejected(): void
    {
        foreach ([
            '',
            'GET POST',
            "GET\n",
            "GET\r",
            'GET/',
            "GET\0",
            'GET:POST',
        ] as $method) {
            try {
                new Request($method);

                self::fail('Expected invalid HTTP method.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame(
                    'Invalid HTTP method.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testWithMethodPreservesOriginalRequest(): void
    {
        $original = new Request(
            'GET',
            '/items?limit=10',
            ['X-Trace' => 'abc'],
            'payload',
        );

        $modified = $original->withMethod('post');

        self::assertNotSame($original, $modified);
        self::assertSame('GET', $original->method);
        self::assertSame('POST', $modified->method);
        self::assertSame($original->target, $modified->target);
        self::assertSame($original->headers, $modified->headers);
        self::assertSame($original->body, $modified->body);
    }

    public function testRequestTargetSeparatesPathAndQueryString(): void
    {
        $cases = [
            ['/', '/', ''],
            ['/items', '/items', ''],
            ['/items?', '/items', ''],
            ['/items?page=2', '/items', 'page=2'],
            [
                '/search?q=php%20framework&sort=asc',
                '/search',
                'q=php%20framework&sort=asc',
            ],
            [
                '/search?token=a=b',
                '/search',
                'token=a=b',
            ],
        ];

        foreach ($cases as [$target, $path, $query]) {
            $request = new Request('GET', $target);

            self::assertSame($target, $request->target);
            self::assertSame($path, $request->path());
            self::assertSame($query, $request->queryString());
        }
    }

    public function testInvalidRequestTargetsAreRejected(): void
    {
        foreach ([
            '',
            'relative/path',
            'https://example.com/path',
            '/items#fragment',
            "/items\n",
            "/items\r",
            "/items\0",
            '/items with space',
            '/items?query=hello world',
        ] as $target) {
            try {
                new Request('GET', $target);

                self::fail('Expected invalid request target.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame(
                    'Invalid HTTP request target.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testWithTargetDoesNotMutateOriginal(): void
    {
        $original = new Request(
            'GET',
            '/old?version=1',
        );

        $modified = $original->withTarget(
            '/new?version=2',
        );

        self::assertNotSame($original, $modified);
        self::assertSame('/old', $original->path());
        self::assertSame('version=1', $original->queryString());
        self::assertSame('/new', $modified->path());
        self::assertSame('version=2', $modified->queryString());
    }

    public function testRequestHeaderLookupIsCaseInsensitive(): void
    {
        $request = new Request(
            'GET',
            '/',
            [
                'Content-Type' => 'application/json',
                'X-Trace-ID' => 'trace-123',
            ],
        );

        self::assertSame(
            'application/json',
            $request->header('content-type'),
        );

        self::assertSame(
            'application/json',
            $request->header('CONTENT-TYPE'),
        );

        self::assertSame(
            'trace-123',
            $request->header('x-trace-id'),
        );

        self::assertNull($request->header('missing'));
    }

    public function testInvalidRequestHeaderNamesAreRejected(): void
    {
        foreach ([
            '',
            'Bad Header',
            'Bad:Header',
            "Bad\nHeader",
            "Bad\rHeader",
        ] as $name) {
            try {
                new Request(
                    'GET',
                    '/',
                    [$name => 'value'],
                );

                self::fail('Expected invalid header name.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame(
                    'Invalid HTTP header name.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testInvalidRequestHeaderValuesAreRejected(): void
    {
        foreach ([
            "value\ninjected",
            "value\r\ninjected",
            "value\0injected",
            "value\tinjected",
            "\x7F",
        ] as $value) {
            try {
                new Request(
                    'GET',
                    '/',
                    ['X-Test' => $value],
                );

                self::fail('Expected invalid header value.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame(
                    'Invalid HTTP header value.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testWithHeaderReplacesHeaderWithoutMutation(): void
    {
        $original = new Request(
            'GET',
            '/',
            ['X-Mode' => 'original'],
        );

        $modified = $original->withHeader(
            'x-mode',
            'updated',
        );

        self::assertNotSame($original, $modified);
        self::assertSame(
            'original',
            $original->header('X-Mode'),
        );

        self::assertSame(
            'updated',
            $modified->header('X-MODE'),
        );

        self::assertCount(1, $modified->headers);
    }

    public function testWithBodyPreservesOtherRequestProperties(): void
    {
        $original = new Request(
            'POST',
            '/submit?draft=1',
            ['Content-Type' => 'text/plain'],
            'original',
        );

        $modified = $original->withBody('updated');

        self::assertNotSame($original, $modified);
        self::assertSame('original', $original->body);
        self::assertSame('updated', $modified->body);
        self::assertSame($original->method, $modified->method);
        self::assertSame($original->target, $modified->target);
        self::assertSame($original->headers, $modified->headers);
    }

    public function testWithRouteParametersCreatesIsolatedCopy(): void
    {
        $original = new Request(
            'GET',
            '/users/42',
        );

        $modified = $original->withRouteParameters([
            'id' => '42',
            'section' => 'profile',
        ]);

        self::assertSame([], $original->routeParameters());
        self::assertNull($original->routeParameter('id'));

        self::assertSame(
            '42',
            $modified->routeParameter('id'),
        );

        self::assertSame(
            'profile',
            $modified->routeParameter('section'),
        );

        self::assertSame(
            [
                'id' => '42',
                'section' => 'profile',
            ],
            $modified->routeParameters(),
        );
    }

    public function testInvalidRouteParameterPairsAreRejected(): void
    {
        $request = new Request();

        $invalidCases = [
            [0 => 'value'],
            ['id' => 42],
            ['id' => null],
            ['id' => ['nested']],
        ];

        foreach ($invalidCases as $parameters) {
            try {
                $request->withRouteParameters($parameters);

                self::fail('Expected invalid route parameters.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame(
                    'Route parameters must be string pairs.',
                    $exception->getMessage(),
                );
            }
        }

        self::assertSame([], $request->routeParameters());
    }

    public function testResponseStatusBoundariesAreValidated(): void
    {
        foreach ([100, 200, 201, 204, 304, 404, 500, 599] as $status) {
            $response = new Response($status);

            self::assertSame($status, $response->status);
        }

        foreach ([0, 99, 600, 999] as $status) {
            try {
                new Response($status);

                self::fail('Expected invalid response status.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame(
                    'HTTP response status must be between 100 and 599.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testBodyForbiddenStatusesRejectContent(): void
    {
        foreach ([100, 101, 103, 204, 304] as $status) {
            try {
                new Response($status, [], 'unexpected body');

                self::fail('Expected body restriction.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame(
                    'This HTTP status must not contain a response body.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testResponseHeaderValidationAndLookup(): void
    {
        $response = new Response(
            200,
            [
                'Content-Type' => 'application/json',
                'X-Trace' => 'abc',
            ],
        );

        self::assertSame(
            'application/json',
            $response->header('CONTENT-TYPE'),
        );

        self::assertSame('abc', $response->header('x-trace'));
        self::assertNull($response->header('missing'));

        foreach ([
            ['Bad Header' => 'value'],
            ['X-Test' => "value\r\ninjected"],
            ['X-Test' => "value\0injected"],
        ] as $headers) {
            $this->expectInvalidResponseHeaders($headers);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function expectInvalidResponseHeaders(
        array $headers,
    ): void {
        try {
            new Response(200, $headers);

            self::fail('Expected invalid response headers.');
        } catch (InvalidArgumentException $exception) {
            self::assertContains(
                $exception->getMessage(),
                [
                    'Invalid HTTP response header name.',
                    'Invalid HTTP response header value.',
                ],
            );
        }
    }

    public function testResponseWithMethodsPreserveOriginal(): void
    {
        $original = new Response(
            200,
            ['X-Version' => '1'],
            'original',
        );

        $withStatus = $original->withStatus(201);
        $withHeader = $original->withHeader('x-version', '2');
        $withBody = $original->withBody('updated');

        self::assertSame(200, $original->status);
        self::assertSame('1', $original->header('X-Version'));
        self::assertSame('original', $original->body);

        self::assertSame(201, $withStatus->status);
        self::assertSame('2', $withHeader->header('X-Version'));
        self::assertSame('updated', $withBody->body);

        self::assertNotSame($original, $withStatus);
        self::assertNotSame($original, $withHeader);
        self::assertNotSame($original, $withBody);
    }

    public function testEncodedDynamicRouteParametersAreDecoded(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/files/{name}',
            static fn (Request $request): Response =>
                new Response(
                    200,
                    [],
                    $request->routeParameter('name') ?? '',
                ),
            [],
            'files.show',
        );

        $url = $kernel->router()->url(
            'files.show',
            ['name' => 'report final.txt'],
        );

        self::assertSame(
            '/files/report%20final.txt',
            $url,
        );

        $response = $kernel->handle(
            new Request('GET', $url),
        );

        self::assertSame(200, $response->status);
        self::assertSame('report final.txt', $response->body);
    }

    public function testMalformedEncodedParametersDoNotMatch(): void
    {
        $kernel = new Kernel(new Application());
        $handlerCalls = 0;

        $kernel->router()->get(
            '/files/{name}',
            static function (
                Request $request,
            ) use (&$handlerCalls): Response {
                ++$handlerCalls;

                return new Response(200);
            },
        );

        foreach ([
            '/files/bad%',
            '/files/bad%2',
            '/files/%2F',
            '/files/%5C',
            '/files/%00',
            '/files/%FF',
        ] as $target) {
            $response = $kernel->handle(
                new Request('GET', $target),
            );

            self::assertSame(404, $response->status);
        }

        self::assertSame(0, $handlerCalls);
    }

    public function testKernelDoesNotLeakRequestParameters(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/users/{id}',
            static fn (Request $request): Response =>
                new Response(
                    200,
                    [],
                    $request->routeParameter('id') ?? '',
                ),
        );

        $kernel->router()->get(
            '/health',
            static fn (Request $request): Response =>
                new Response(
                    200,
                    [],
                    $request->routeParameter('id') ?? 'clean',
                ),
        );

        $first = $kernel->handle(
            new Request('GET', '/users/42'),
        );

        $second = $kernel->handle(
            new Request('GET', '/health'),
        );

        $third = $kernel->handle(
            new Request('GET', '/users/99'),
        );

        self::assertSame('42', $first->body);
        self::assertSame('clean', $second->body);
        self::assertSame('99', $third->body);
    }
}
