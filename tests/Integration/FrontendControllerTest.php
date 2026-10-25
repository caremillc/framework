<?php

declare(strict_types=1);

namespace Careminate\Tests\Integration;

use Careminate\Http\Emission\BufferedResponseOutput;
use Careminate\Http\Emission\ResponseEmitter;
use Careminate\Http\Kernel;
use Careminate\Http\Response;
use Careminate\Http\Runtime\HttpRunner;
use Careminate\Http\ServerRequestFactory;
use PHPUnit\Framework\TestCase;

final class FrontendControllerTest extends TestCase
{
    public function testFrontendReturnsHtml(): void
    {
        [$response, $output] = $this->request('GET', '/');

        self::assertSame(200, $response->status);
        self::assertSame(
            'text/html; charset=utf-8',
            $output->headers()['content-type'],
        );
        self::assertStringContainsString(
            '<title>Careminate Framework</title>',
            $output->body(),
        );
    }

    public function testFrontendContainsApiIntegration(): void
    {
        [$response, $output] = $this->request('GET', '/');

        self::assertSame(200, $response->status);
        self::assertStringContainsString(
            "fetch('/api/status')",
            $output->body(),
        );
    }

    public function testHealthEndpoint(): void
    {
        [$response, $output] = $this->request(
            'GET',
            '/health',
        );

        self::assertSame(200, $response->status);
        self::assertSame('Careminate OK', $output->body());
    }


    public function testJsonStatusEndpoint(): void
    {
        [$response, $output] = $this->request(
            'GET',
            '/api/status',
        );

        $data = json_decode(
            $output->body(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($data);

        self::assertSame(200, $response->status);
        self::assertSame('Careminate', $data['framework']);
        self::assertSame('running', $data['status']);
        self::assertSame(PHP_VERSION, $data['php']);
    }


    public function testEchoEndpoint(): void
    {
        [$response, $output] = $this->request(
            'POST',
            '/api/echo',
            '{"message":"Hello Careminate"}',
        );

        self::assertSame(200, $response->status);
        self::assertSame(
            '{"message":"Hello Careminate"}',
            $output->body(),
        );
    }

    public function testEchoRejectsMissingMessage(): void
    {
        [$response, $output] = $this->request(
            'POST',
            '/api/echo',
            '{}',
        );

        self::assertSame(400, $response->status);
        self::assertSame(
            '{"error":"message must be a string"}',
            $output->body(),
        );
    }

    public function testUnknownRouteReturns404(): void
    {
        [$response, $output] = $this->request(
            'GET',
            '/missing',
        );

        self::assertSame(404, $response->status);
        self::assertSame('Not Found', $output->body());
    }

    public function testWrongMethodReturns405(): void
    {
        [$response, $output] = $this->request(
            'POST',
            '/health',
        );

        self::assertSame(405, $response->status);
        self::assertSame(
            'GET',
            $output->headers()['allow'],
        );
    }

    public function testHeadDoesNotEmitBody(): void
    {
        [$response, $output] = $this->request(
            'HEAD',
            '/health',
        );

        self::assertSame(405, $response->status);
        self::assertSame('', $output->body());
    }

    public function testMiddlewareAddsSecurityHeader(): void
    {
        [$response, $output] = $this->request(
            'GET',
            '/health',
        );

        self::assertSame(200, $response->status);
        self::assertSame(
            'DENY',
            $output->headers()['x-frame-options'],
        );
    }

    public function testFrontendHasContentSecurityPolicy(): void
    {
        [$response, $output] = $this->request('GET', '/');

        self::assertSame(200, $response->status);
        self::assertArrayHasKey(
            'content-security-policy',
            $output->headers(),
        );
    }

    public function testApplicationCanBootAndTerminate(): void
    {
        $kernel = $this->createKernel();
        $output = new BufferedResponseOutput();

        $runner = new HttpRunner(
            new ServerRequestFactory(),
            $kernel,
            new ResponseEmitter($output),
        );

        $response = $runner->run([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/health',
        ]);

        self::assertSame(200, $response->status);

        $kernel->terminate();

        $this->expectException(\LogicException::class);

        $kernel->handle(
            new \Careminate\Http\Request(),
        );
    }

    /**
     * @return array{Response, BufferedResponseOutput}
     */
    private function request(
        string $method,
        string $uri,
        string $body = '',
    ): array {
        $kernel = $this->createKernel();
        $output = new BufferedResponseOutput();

        $runner = new HttpRunner(
            new ServerRequestFactory(),
            $kernel,
            new ResponseEmitter($output),
        );

        try {
            $response = $runner->run(
                [
                    'REQUEST_METHOD' => $method,
                    'REQUEST_URI' => $uri,
                ],
                $body,
            );

            return [$response, $output];
        } finally {
            $kernel->terminate();
        }
    }


    private function createKernel(): Kernel
    {
        $bootstrap = require dirname(__DIR__, 2)
            . '/examples/frontend-demo/bootstrap.php';

        self::assertIsCallable($bootstrap);

        $kernel = $bootstrap();

        self::assertInstanceOf(Kernel::class, $kernel);

        return $kernel;
    }

    public function testMalformedJsonReturns400(): void
    {
        [$response, $output] = $this->request(
            'POST',
            '/api/echo',
            '{"message":',
        );

        self::assertSame(400, $response->status);
        self::assertSame(
            '{"error":"invalid JSON"}',
            $output->body(),
        );
    }

    public function testJsonArrayIsRejected(): void
    {
        [$response, $output] = $this->request(
            'POST',
            '/api/echo',
            '["hello"]',
        );

        self::assertSame(400, $response->status);
        self::assertSame(
            '{"error":"message must be a string"}',
            $output->body(),
        );
    }

    public function testEchoPreservesUnicode(): void
    {
        [$response, $output] = $this->request(
            'POST',
            '/api/echo',
            '{"message":"مرحبا"}',
        );

        $decoded = json_decode(
            $output->body(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($decoded);
        self::assertSame(200, $response->status);
        self::assertSame(
            'مرحبا',
            $decoded['message'],
        );
    }
}
