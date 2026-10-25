<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Http\Runtime;

use Careminate\Foundation\Application;
use Careminate\Http\Emission\BufferedResponseOutput;
use Careminate\Http\Emission\ResponseEmitter;
use Careminate\Http\Kernel;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Http\Runtime\HttpRunner;
use Careminate\Http\ServerRequestFactory;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HttpRuntimeHardeningTest extends TestCase
{
    public function testHeadResponseSuppressesBody(): void
    {
        $kernel = new Kernel(new Application());
        $output = new BufferedResponseOutput();

        $kernel->router()->add(
            'HEAD',
            '/resource',
            static fn (Request $request): Response =>
                new Response(200, [], 'secret-body'),
        );

        $response = $this->runner($kernel, $output)->run(
            $this->server('HEAD', '/resource'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
        self::assertSame('', $output->body());
    }

    public function testHeadPreservesResponseHeaders(): void
    {
        $kernel = new Kernel(new Application());
        $output = new BufferedResponseOutput();

        $kernel->router()->add(
            'HEAD',
            '/resource',
            static fn (Request $request): Response =>
                new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    '{"ok":true}',
                ),
        );

        $this->runner($kernel, $output)->run(
            $this->server('HEAD', '/resource'),
        );

        self::assertSame(
            'application/json',
            $output->headers()['content-type'],
        );
        self::assertSame('', $output->body());
    }

    public function testHeadDoesNotImplicitlyMatchGet(): void
    {
        $kernel = new Kernel(new Application());
        $output = new BufferedResponseOutput();

        $kernel->router()->get(
            '/resource',
            static fn (Request $request): Response =>
                new Response(200, [], 'resource'),
        );

        $response = $this->runner($kernel, $output)->run(
            $this->server('HEAD', '/resource'),
        );

        self::assertSame(405, $response->status);
        self::assertSame('', $output->body());
        self::assertSame('GET', $output->headers()['allow']);
    }

    public function testGetResponseStillEmitsBody(): void
    {
        $kernel = new Kernel(new Application());
        $output = new BufferedResponseOutput();

        $kernel->router()->get(
            '/resource',
            static fn (Request $request): Response =>
                new Response(200, [], 'resource'),
        );

        $response = $this->runner($kernel, $output)->run(
            $this->server('GET', '/resource'),
        );

        self::assertSame('resource', $response->body);
        self::assertSame('resource', $output->body());
    }

    public function testHeadNotFoundRemainsBodyless(): void
    {
        $kernel = new Kernel(new Application());
        $output = new BufferedResponseOutput();

        $response = $this->runner($kernel, $output)->run(
            $this->server('HEAD', '/missing'),
        );

        self::assertSame(404, $response->status);
        self::assertSame('', $response->body);
        self::assertSame('', $output->body());
    }

    public function testNoContentResponseRemainsBodyless(): void
    {
        $kernel = new Kernel(new Application());
        $output = new BufferedResponseOutput();

        $kernel->router()->get(
            '/empty',
            static fn (Request $request): Response =>
                new Response(204),
        );

        $response = $this->runner($kernel, $output)->run(
            $this->server('GET', '/empty'),
        );

        self::assertSame(204, $response->status);
        self::assertSame('', $output->body());
    }

    public function testNotModifiedResponseRemainsBodyless(): void
    {
        $kernel = new Kernel(new Application());
        $output = new BufferedResponseOutput();

        $kernel->router()->get(
            '/cached',
            static fn (Request $request): Response =>
                new Response(304),
        );

        $response = $this->runner($kernel, $output)->run(
            $this->server('GET', '/cached'),
        );

        self::assertSame(304, $response->status);
        self::assertSame('', $output->body());
    }

    public function testInformationalResponseRemainsBodyless(): void
    {
        $kernel = new Kernel(new Application());
        $output = new BufferedResponseOutput();

        $kernel->router()->get(
            '/info',
            static fn (Request $request): Response =>
                new Response(103),
        );

        $response = $this->runner($kernel, $output)->run(
            $this->server('GET', '/info'),
        );

        self::assertSame(103, $response->status);
        self::assertSame('', $output->body());
    }

    public function testFailedEmissionCannotBeRetried(): void
    {
        $kernel = new Kernel(new Application());
        $output = new BufferedResponseOutput();

        $output->sendStatus(200);

        $runner = $this->runner($kernel, $output);

        try {
            $runner->run($this->server('GET', '/'));
            self::fail('Expected emission failure.');
        } catch (LogicException) {
            // Expected.
        }

        self::assertTrue($runner->hasExecuted());

        $this->expectException(LogicException::class);

        $runner->run($this->server('GET', '/'));
    }

    public function testHeadHandlerExceptionPropagates(): void
    {
        $kernel = new Kernel(new Application());
        $output = new BufferedResponseOutput();

        $kernel->router()->add(
            'HEAD',
            '/failure',
            static function (Request $request): Response {
                throw new RuntimeException('Handler failed');
            },
        );

        $runner = $this->runner($kernel, $output);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Handler failed');

        $runner->run(
            $this->server('HEAD', '/failure'),
        );
    }

    public function testHeadBodySuppressionPreservesStatus(): void
    {
        $kernel = new Kernel(new Application());
        $output = new BufferedResponseOutput();

        $kernel->router()->add(
            'HEAD',
            '/accepted',
            static fn (Request $request): Response =>
                new Response(202, [], 'accepted'),
        );

        $response = $this->runner($kernel, $output)->run(
            $this->server('HEAD', '/accepted'),
        );

        self::assertSame(202, $response->status);
        self::assertSame(202, $output->status());
        self::assertSame('', $output->body());
    }

    public function testHeadResponsePreservesContentLengthHeader(): void
    {
        $kernel = new Kernel(new Application());
        $output = new BufferedResponseOutput();

        $kernel->router()->add(
            'HEAD',
            '/resource',
            static fn (Request $request): Response =>
                new Response(
                    200,
                    ['Content-Length' => '8'],
                    'resource',
                ),
        );

        $response = $this->runner($kernel, $output)->run(
            $this->server('HEAD', '/resource'),
        );

        self::assertSame('', $response->body);
        self::assertSame(
            '8',
            $output->headers()['content-length'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function server(
        string $method,
        string $target,
    ): array {
        return [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $target,
        ];
    }

    private function runner(
        Kernel $kernel,
        BufferedResponseOutput $output,
    ): HttpRunner {
        return new HttpRunner(
            new ServerRequestFactory(),
            $kernel,
            new ResponseEmitter($output),
        );
    }
}
