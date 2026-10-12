<?php

declare (strict_types=1);

/**
 * @return array{
 *     status: int,
 *     headers: array<string, list<string>>,
 *     body: string
 * }
 */
function smokeRequest(
    string $address,
    string $method,
    string $path,
    string $requestBody = '',
): array {
    $errorCode    = 0;
    $errorMessage = '';

    $socket = @stream_socket_client(
        'tcp://' . $address,
        $errorCode,
        $errorMessage,
        1.0,
    );

    if ($socket === false) {
        throw new RuntimeException('Could not connect to the test server.');
    }

    try {
        stream_set_timeout($socket, 5);

        $request = sprintf(
            "%s %s HTTP/1.1\r\nHost: %s\r\nConnection: close\r\n",
            $method,
            $path,
            $address,
        );

        if ($requestBody !== '' || $method === 'POST') {
            $request .= sprintf(
                "Content-Type: application/octet-stream\r\nContent-Length: %d\r\n",
                strlen($requestBody),
            );
        }

        $request .= "\r\n" . $requestBody;

        $offset = 0;
        $length = strlen($request);

        while ($offset < $length) {
            $written = fwrite($socket, substr($request, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not send the HTTP request.');
            }

            $offset += $written;
        }

        $raw      = stream_get_contents($socket);
        $metadata = stream_get_meta_data($socket);

        if ($raw === false || $metadata['timed_out']) {
            throw new RuntimeException('Could not read a complete HTTP response.');
        }
    } finally {
        fclose($socket);
    }

    $parts = explode("\r\n\r\n", $raw, 2);

    if (count($parts) !== 2) {
        throw new RuntimeException('The HTTP response has no header terminator.');
    }

    $lines      = explode("\r\n", $parts[0]);
    $statusLine = array_shift($lines);

    if (
        preg_match('/\AHTTP\/1\.[01] ([0-9]{3})(?: |$)/', $statusLine, $matches) !== 1
    ) {
        throw new RuntimeException('The HTTP response status line is invalid.');
    }

    $headers  = [];

    foreach ($lines as $line) {
        $separator = strpos($line, ':');

        if ($separator === false) {
            throw new RuntimeException('The HTTP response contains an invalid header.');
        }

        $name  = strtolower(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1), " \t");

        $headers[$name][] = $value;
    }

    return [
        'status'  => (int) $matches[1],
        'headers' => $headers,
        'body'    => $parts[1],
    ];
}

function smokeCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$root         = dirname(__DIR__);
$errorCode    = 0;
$errorMessage = '';

$listener = stream_socket_server(
    'tcp://127.0.0.1:0',
    $errorCode,
    $errorMessage,
);

if ($listener === false) {
    throw new RuntimeException('Could not reserve a local test port.');
}

$address  = stream_socket_get_name($listener, false);
fclose($listener);

if ($address === false) {
    throw new RuntimeException('Could not determine the local test address.');
}

$log = tempnam(sys_get_temp_dir(), 'flint-http-');

if ($log === false) {
    throw new RuntimeException('Could not create the HTTP test log.');
}

$token = bin2hex(random_bytes(16));

/** @var array<string, string> $environment */
$environment  = getenv();

$environment['FLINT_HTTP_SMOKE_TOKEN'] = $token;
unset($environment['PHP_CLI_SERVER_WORKERS']);

$process  = null;
$exitCode = 0;

try {
    $process = proc_open(
        [
            PHP_BINARY,
            '-d',
            'display_errors=0',
            '-d',
            'log_errors=1',
            '-d',
            'output_buffering=0',
            '-d',
            'output_handler=',
            '-d',
            'zlib.output_compression=0',
            '-d',
            'default_mimetype=',
            '-S',
            $address,
            '-t',
            $root . '/tests/Fixtures/Http',
            $root . '/tests/Fixtures/Http/router.php',
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ],
        $pipes,
        $root,
        $environment,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start the PHP test server.');
    }

    fclose($pipes[0]);

    $ready = false;

    for ($attempt = 0; $attempt < 50; ++$attempt) {
        $state = proc_get_status($process);

        if (! $state['running']) {
            throw new RuntimeException('The PHP test server stopped during startup.');
        }

        try {
            $probe = smokeRequest($address, 'GET', '/ready');

            if ($probe['status'] === 200 && $probe['body'] === $token) {
                $ready = true;

                break;
            }
        } catch (RuntimeException) {
            // The server may still be starting.
        }

        usleep(100_000);
    }

    smokeCheck($ready, 'The PHP test server did not become ready.');

    /**
     * @var list<array{
     *     0: string,
     *     1: string,
     *     2: int,
     *     3: string,
     *     4: string|null,
     *     5: string
     * }> $cases
     */
    $cases = [
        ['GET', '/text', 200, "Caf\xC3\xA9", '5', ''],
        ['HEAD', '/text', 200, '', '5', ''],
        ['GET', '/json', 200, '{"ready":true}', '14', ''],
        ['GET', '/zero', 200, '0', '1', ''],
        ['GET', '/cookies', 200, '', '0', ''],
        ['GET', '/location', 202, 'Accepted', '8', ''],
        ['GET', '/empty', 204, '', null, ''],
        ['GET', '/reset', 205, '', '0', ''],
        ['GET', '/cached', 304, '', null, ''],

        // Request capture: empty, binary, exact limit, and over limit.
        ['POST', '/echo', 200, '', '0', ''],
        ['POST', '/echo', 200, "\0\xFF\r\n", '4', "\0\xFF\r\n"],
        ['POST', '/echo', 200, '12345678', '8', '12345678'],
        ['POST', '/echo', 413, 'Too large', '9', '123456789'],

        // The server must still handle another request after rejection.
        ['GET', '/text', 200, "Caf\xC3\xA9", '5', ''],
    ];

    foreach ($cases as [$method, $path, $status, $body, $length, $requestBody]) {
        $response = smokeRequest(
            $address,
            $method,
            $path,
            $requestBody,
        );

        $label = $method . ' ' . $path;

        smokeCheck(
            $response['status'] === $status,
            $label . ': unexpected status.',
        );

        smokeCheck(
            $response['body'] === $body,
            $label . ': unexpected body bytes.',
        );

        smokeCheck(
            ($response['headers']['content-length'] ?? [])
            === ($length === null ? [] : [$length]),
            $label . ': unexpected Content-Length.',
        );

        smokeCheck(
            ! isset($response['headers']['transfer-encoding']),
            $label . ': unexpected Transfer-Encoding.',
        );

        smokeCheck(
            ! isset($response['headers']['x-stale']),
            $label . ': a previously queued header was retained.',
        );

        if ($path === '/text') {
            smokeCheck(
                ($response['headers']['content-type'] ?? [])
                === ['text/plain; charset=utf-8'],
                $label . ': unexpected text content type.',
            );
        }

        if ($path === '/json') {
            smokeCheck(
                ($response['headers']['content-type'] ?? [])
                === ['application/json'],
                $label . ': unexpected JSON content type.',
            );
        }

        if ($path === '/cookies') {
            smokeCheck(
                ($response['headers']['set-cookie'] ?? [])
                === ['first=1; Path=/', 'second=2; Path=/'],
                $label . ': cookie values were combined, replaced, or reordered.',
            );
        }

        if ($path === '/location') {
            smokeCheck(
                ($response['headers']['location'] ?? []) === ['/next'],
                $label . ': unexpected Location header.',
            );
        }

        if ($path === '/cached') {
            smokeCheck(
                ($response['headers']['etag'] ?? []) === ['"example"'],
                $label . ': ETag was not preserved.',
            );
        }

        if ($path === '/echo' && $status === 200) {
            smokeCheck(
                ($response['headers']['content-type'] ?? [])
                === ['application/octet-stream'],
                $label . ': unexpected echo content type.',
            );

            smokeCheck(
                ($response['headers']['x-captured-method'] ?? [])
                === ['POST'],
                $label . ': the request method was not captured.',
            );

            smokeCheck(
                ($response['headers']['x-captured-content-type'] ?? [])
                === ['application/octet-stream'],
                $label . ': the request content type was not captured.',
            );
        }

        echo sprintf('PASS: %s -> %d', $label, $status) . PHP_EOL;
    }

    echo sprintf(
        'PASS: %d HTTP requests and all response assertions.',
        count($cases),
    ) . PHP_EOL;
} catch (Throwable $exception) {
    $exitCode = 1;

    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);

    $diagnostics = file_get_contents($log);

    if ($diagnostics !== false && $diagnostics !== '') {
        fwrite(STDERR, $diagnostics);
    }
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }

    if (is_file($log)) {
        unlink($log);
    }
}

exit($exitCode);
