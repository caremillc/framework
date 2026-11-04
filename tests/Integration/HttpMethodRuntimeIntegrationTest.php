<?php

declare(strict_types=1);

namespace Careminate\Tests\Integration;

use Careminate\Foundation\Application;
use Careminate\Http\Emission\BufferedResponseOutput;
use Careminate\Http\Emission\ResponseEmitter;
use Careminate\Http\Kernel;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Runtime\HttpRunner;
use Careminate\Http\ServerRequestFactory;
use PHPUnit\Framework\TestCase;

final class HttpMethodRuntimeIntegrationTest extends TestCase
{
    public function testHeadGetFallbackPreservesMetadataButDoesNotEmitBody(): void
    {
        $kernel = new Kernel(new Application());
        $observedMethod = null;

        $kernel->router()->get(
            '/health',
            static function (Request $request) use (&$observedMethod): Response {
                $observedMethod = $request->method;

                return new Response(
                    200,
                    ['Content-Type' => 'text/plain', 'X-Source' => 'get'],
                    'visible only on GET',
                );
            },
        );

        [$response, $output] = self::executeRequest(
            $kernel,
            'HEAD',
            '/health',
        );

        self::assertSame('HEAD', $observedMethod);
        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
        self::assertSame(200, $output->status());
        self::assertSame(
            'get',
            $output->headers()['x-source'] ?? null,
        );
        self::assertSame(
            'text/plain',
            $output->headers()['content-type'] ?? null,
        );
        self::assertSame('', $output->body());
    }

    public function testExplicitHeadEmitsItsStatusAndHeadersWithoutBody(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/resource',
            self::handler('get body'),
        );

        $kernel->router()->add(
            'HEAD',
            '/resource',
            static fn (Request $request): Response => new Response(
                202,
                ['X-Handler' => 'explicit-head'],
                'head body',
            ),
        );

        [$response, $output] = self::executeRequest(
            $kernel,
            'HEAD',
            '/resource',
        );

        self::assertSame(202, $response->status);
        self::assertSame('', $response->body);
        self::assertSame(202, $output->status());
        self::assertSame(
            'explicit-head',
            $output->headers()['x-handler'] ?? null,
        );
        self::assertSame('', $output->body());
    }

    public function testHeadAgainstPostOnlyPathEmitsBodyless405(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->post(
            '/resource',
            self::handler('created'),
        );

        [$response, $output] = self::executeRequest(
            $kernel,
            'HEAD',
            '/resource',
        );

        self::assertSame(405, $response->status);
        self::assertSame('', $response->body);
        self::assertSame(405, $output->status());
        self::assertSame(
            'OPTIONS, POST',
            $output->headers()['allow'] ?? null,
        );
        self::assertSame('', $output->body());
    }

    public function testMissingHeadPathEmitsBodyless404(): void
    {
        $kernel = new Kernel(new Application());

        [$response, $output] = self::executeRequest(
            $kernel,
            'HEAD',
            '/missing',
        );

        self::assertSame(404, $response->status);
        self::assertSame(404, $output->status());
        self::assertArrayNotHasKey(
            'allow',
            $output->headers(),
        );
        self::assertSame('', $output->body());
    }

    public function testAutomaticOptionsEmits204AndDeterministicAllowHeader(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->add(
            'PATCH',
            '/resource',
            self::handler('patch'),
        );

        $kernel->router()->post(
            '/resource',
            self::handler('post'),
        );

        $kernel->router()->get(
            '/resource',
            self::handler('get'),
        );

        [$response, $output] = self::executeRequest(
            $kernel,
            'OPTIONS',
            '/resource?view=full',
        );

        self::assertSame(204, $response->status);
        self::assertSame('', $response->body);
        self::assertSame(204, $output->status());

        self::assertSame(
            'GET, HEAD, OPTIONS, PATCH, POST',
            $output->headers()['allow'] ?? null,
        );

        self::assertSame('', $output->body());
        self::assertSame(3, $kernel->router()->count());
    }

    public function testAutomaticOptionsDoesNotExecuteGetHandler(): void
    {
        $kernel = new Kernel(new Application());
        $calls = 0;

        $kernel->router()->get(
            '/resource',
            static function (Request $request) use (&$calls): Response {
                ++$calls;

                return new Response(200, [], 'get body');
            },
        );

        [$response, $output] = self::executeRequest(
            $kernel,
            'OPTIONS',
            '/resource',
        );

        self::assertSame(204, $response->status);
        self::assertSame(0, $calls);
        self::assertSame('', $output->body());
    }

    public function testExplicitOptionsEmitsHandlerResponse(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/resource',
            self::handler('get body'),
        );

        $kernel->router()->add(
            'OPTIONS',
            '/resource',
            static fn (Request $request): Response => new Response(
                200,
                ['X-Options' => 'explicit'],
                'custom options',
            ),
        );

        [$response, $output] = self::executeRequest(
            $kernel,
            'OPTIONS',
            '/resource',
        );

        self::assertSame(200, $response->status);
        self::assertSame('custom options', $response->body);
        self::assertSame(200, $output->status());
        self::assertSame(
            'explicit',
            $output->headers()['x-options'] ?? null,
        );
        self::assertSame('custom options', $output->body());
    }

    public function testOptionsForMissingPathEmits404WithoutAllowHeader(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/known',
            self::handler('known'),
        );

        [$response, $output] = self::executeRequest(
            $kernel,
            'OPTIONS',
            '/missing',
        );

        self::assertSame(404, $response->status);
        self::assertSame(404, $output->status());
        self::assertArrayNotHasKey(
            'allow',
            $output->headers(),
        );
        self::assertSame('Not Found', $output->body());
    }

    public function testAutomaticOptionsRespectsDynamicRouteConstraint(): void
    {
        $matchedKernel = new Kernel(new Application());

        $matchedKernel->router()->get(
            '/users/{id}',
            self::handler('user'),
            ['id' => '[0-9]+'],
        );

        [$matched, $matchedOutput] = self::executeRequest(
            $matchedKernel,
            'OPTIONS',
            '/users/42',
        );

        self::assertSame(204, $matched->status);
        self::assertSame(
            'GET, HEAD, OPTIONS',
            $matchedOutput->headers()['allow'] ?? null,
        );

        $unmatchedKernel = new Kernel(new Application());

        $unmatchedKernel->router()->get(
            '/users/{id}',
            self::handler('user'),
            ['id' => '[0-9]+'],
        );

        [$unmatched, $unmatchedOutput] = self::executeRequest(
            $unmatchedKernel,
            'OPTIONS',
            '/users/letters',
        );

        self::assertSame(404, $unmatched->status);
        self::assertArrayNotHasKey(
            'allow',
            $unmatchedOutput->headers(),
        );
        self::assertSame(
            'Not Found',
            $unmatchedOutput->body(),
        );
    }

    public function testGlobalMiddlewareCanDecorateAutomaticOptionsResponse(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/resource',
            self::handler('get'),
        );

        $observedMethod = null;

        $kernel->closureMiddleware(
            /** @param \Closure(Request): Response $next */
            static function (
                Request $request,
                \Closure $next,
            ) use (&$observedMethod): Response {
                $observedMethod = $request->method;

                /** @var Response $response */
                $response = $next($request);

                return $response->withHeader(
                    'X-Middleware',
                    'executed',
                );
            },
        );

        [$response, $output] = self::executeRequest(
            $kernel,
            'OPTIONS',
            '/resource',
        );

        self::assertSame('OPTIONS', $observedMethod);
        self::assertSame(204, $response->status);
        self::assertSame(
            'executed',
            $output->headers()['x-middleware'] ?? null,
        );
        self::assertSame('', $output->body());
    }

    public function testFinalHeadBodySuppressionOccursAfterGlobalMiddleware(): void
    {
        $kernel = new Kernel(new Application());

        $kernel->router()->get(
            '/resource',
            self::handler('get body'),
        );

        $kernel->closureMiddleware(
            /** @param \Closure(Request): Response $next */
            static function (
                Request $request,
                \Closure $next,
            ): Response {
                /** @var Response $response */
                $response = $next($request);

                return $response
                    ->withBody('middleware-added body')
                    ->withHeader(
                        'X-Middleware',
                        'executed',
                    );
            },
        );

        [$response, $output] = self::executeRequest(
            $kernel,
            'HEAD',
            '/resource',
        );

        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
        self::assertSame(
            'executed',
            $output->headers()['x-middleware'] ?? null,
        );
        self::assertSame('', $output->body());
    }

    /**
     * @return array{Response, BufferedResponseOutput}
     */
    private static function executeRequest(
        Kernel $kernel,
        string $method,
        string $uri,
    ): array {
        $output = new BufferedResponseOutput();

        $runner = new HttpRunner(
            new ServerRequestFactory(),
            $kernel,
            new ResponseEmitter($output),
        );

        try {
            $response = $runner->run([
                'REQUEST_METHOD' => $method,
                'REQUEST_URI' => $uri,
            ]);

            return [$response, $output];
        } finally {
            $kernel->terminate();
        }
    }

    /**
     * @return \Closure(Request): Response
     */
    private static function handler(string $body): \Closure
    {
        return static fn (Request $request): Response =>
            new Response(200, [], $body);
    }
}
