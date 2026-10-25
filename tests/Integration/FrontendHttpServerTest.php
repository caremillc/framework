<?php

declare(strict_types=1);

namespace Careminate\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FrontendHttpServerTest extends TestCase
{
    /** @var resource|null */
    private static $serverProcess = null;

    /** @var array<int, resource> */
    private static array $pipes = [];

    private static string $baseUrl = '';

    public static function setUpBeforeClass(): void
    {
        $public = dirname(__DIR__, 2)
            . '/examples/frontend-demo/public';

        $router = $public . '/router.php';

        if (!is_file($router)) {
            throw new RuntimeException(
                'Frontend demo router was not found.',
            );
        }

        $socket = stream_socket_server(
            'tcp://127.0.0.1:0',
            $errorCode,
            $errorMessage,
        );

        if ($socket === false) {
            throw new RuntimeException(
                'Unable to reserve a local test port: '
                . $errorMessage,
            );
        }

        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        if (!is_string($address)) {
            throw new RuntimeException(
                'Unable to determine test port.',
            );
        }

        $separator = strrpos($address, ':');

        if ($separator === false) {
            throw new RuntimeException(
                'Invalid local socket address.',
            );
        }

        $port = substr($address, $separator + 1);

        self::$baseUrl = 'http://127.0.0.1:' . $port;

        $command = [
            PHP_BINARY,
            '-S',
            '127.0.0.1:' . $port,
            '-t',
            $public,
            $router,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $command,
            $descriptors,
            self::$pipes,
            dirname(__DIR__, 2),
        );

        if (!is_resource($process)) {
            throw new RuntimeException(
                'Unable to start PHP HTTP test server.',
            );
        }

        self::$serverProcess = $process;

        fclose(self::$pipes[0]);
        unset(self::$pipes[0]);

        self::waitForServer();
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        self::$pipes = [];

        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }

        self::$serverProcess = null;
        self::$baseUrl = '';
    }

    public function testHomepageReturnsHtml(): void
    {
        [$status, $headers, $body] = $this->http('GET', '/');

        self::assertSame(200, $status);
        self::assertStringContainsString(
            'text/html',
            $headers['content-type'],
        );
        self::assertStringContainsString(
            'Careminate Framework',
            $body,
        );
    }

    public function testHealthEndpoint(): void
    {
        [$status, $headers, $body] = $this->http(
            'GET',
            '/health',
        );

        self::assertSame(200, $status);
        self::assertSame('Careminate OK', $body);
        self::assertSame(
            'DENY',
            $headers['x-frame-options'],
        );
    }

    public function testJsonApi(): void
    {
        [$status, $headers, $body] = $this->http(
            'GET',
            '/api/status',
        );

        $data = json_decode(
            $body,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($data);
        self::assertSame(200, $status);
        self::assertSame('Careminate', $data['framework']);
        self::assertSame('running', $data['status']);
        self::assertSame(
            'application/json',
            $headers['content-type'],
        );
    }

    public function testEchoEndpoint(): void
    {
        [$status, $headers, $body] = $this->http(
            'POST',
            '/api/echo',
            '{"message":"HTTP integration"}',
            ['Content-Type: application/json'],
        );

        self::assertSame(200, $status);
        self::assertSame(
            '{"message":"HTTP integration"}',
            $body,
        );
    }

    public function testMalformedJsonReturns400(): void
    {
        [$status, $headers, $body] = $this->http(
            'POST',
            '/api/echo',
            '{"message":',
            ['Content-Type: application/json'],
        );

        self::assertSame(400, $status);
        self::assertSame(
            '{"error":"invalid JSON"}',
            $body,
        );
    }

    public function testUnknownRouteReturns404(): void
    {
        [$status, $headers, $body] = $this->http(
            'GET',
            '/missing',
        );

        self::assertSame(404, $status);
        self::assertSame('Not Found', $body);
    }

    public function testWrongMethodReturns405(): void
    {
        [$status, $headers, $body] = $this->http(
            'POST',
            '/health',
        );

        self::assertSame(405, $status);
        self::assertSame('GET', $headers['allow']);
    }

    public function testHeadResponseHasNoBody(): void
    {
        [$status, $headers, $body] = $this->http(
            'HEAD',
            '/health',
        );

        self::assertSame(405, $status);
        self::assertSame('', $body);
    }

    /**
     * @param list<string> $extraHeaders
     *
     * @return array{int, array<string, string>, string}
     */
    private function http(
        string $method,
        string $path,
        string $body = '',
        array $extraHeaders = [],
    ): array {
        $headers = array_merge(
            ['Connection: close'],
            $extraHeaders,
        );

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 5,
            ],
        ]);

        $stream = fopen(
            self::$baseUrl . $path,
            'rb',
            false,
            $context,
        );

        if ($stream === false) {
            throw new RuntimeException(
                'HTTP request failed: ' . $path,
            );
        }

        try {
            $metadata = stream_get_meta_data($stream);
            $result = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        if ($result === false) {
            throw new RuntimeException(
                'Unable to read HTTP response.',
            );
        }

        $rawHeaders = $metadata['wrapper_data'] ?? [];

        if (!is_array($rawHeaders)) {
            throw new RuntimeException(
                'Invalid HTTP response metadata.',
            );
        }

        $status = 0;

        /** @var array<string, string> $parsedHeaders */
        $parsedHeaders = [];

        foreach ($rawHeaders as $line) {
            if (!is_string($line)) {
                continue;
            }

            if (preg_match(
                '/^HTTP\/\d(?:\.\d)?\s+(\d{3})/',
                $line,
                $matches,
            ) === 1) {
                $status = (int) $matches[1];
                $parsedHeaders = [];
                continue;
            }

            $separator = strpos($line, ':');

            if ($separator === false) {
                continue;
            }

            $name = strtolower(
                trim(substr($line, 0, $separator)),
            );

            $value = trim(
                substr($line, $separator + 1),
            );

            $parsedHeaders[$name] = $value;
        }

        return [$status, $parsedHeaders, $result];
    }

    private static function waitForServer(): void
    {
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client(
                str_replace('http://', 'tcp://', self::$baseUrl),
                $errorCode,
                $errorMessage,
                0.1,
            );

            if (is_resource($socket)) {
                fclose($socket);
                return;
            }

            usleep(50000);
        }

        throw new RuntimeException(
            'PHP HTTP test server did not start.',
        );
    }
}
