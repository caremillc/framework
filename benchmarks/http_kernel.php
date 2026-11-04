<?php

declare(strict_types=1);

/**
 * Careminate HTTP benchmark — Day 17, Increment 11.
 * Run from the framework repository root:
 * php benchmarks/http_kernel.php --iterations=1000 --samples=7
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Careminate\Foundation\Application;
use Careminate\Http\Kernel;
use Careminate\Http\Middleware\Middleware;
use Careminate\Http\Request;
use Careminate\Http\Response;

$options = getopt('', ['iterations::', 'samples::', 'warmup::', 'output::']);

$positiveInteger = static function (string $name, int $default) use ($options): int {
    $raw = $options[$name] ?? $default;
    if (!is_scalar($raw) || filter_var($raw, FILTER_VALIDATE_INT) === false || (int) $raw < 1) {
        throw new InvalidArgumentException(sprintf('--%s must be a positive integer.', $name));
    }
    return (int) $raw;
};

$iterations = $positiveInteger('iterations', 1000);
$samples = $positiveInteger('samples', 7);
$warmup = $positiveInteger('warmup', 200);
$output = $options['output'] ?? null;
if ($output !== null && (!is_string($output) || $output === '')) {
    throw new InvalidArgumentException('--output requires a file path.');
}

/** @return array{median_ms: float, p95_ms: float, requests_per_second: float, sample_ms: list<float>} */
function measure(Kernel $kernel, Request $request, int $expectedStatus, int $warmup, int $iterations, int $samples): array
{
    $check = static function (Response $response) use ($expectedStatus): void {
        if ($response->status !== $expectedStatus) {
            throw new RuntimeException(sprintf('Expected HTTP %d; received %d.', $expectedStatus, $response->status));
        }
    };

    for ($i = 0; $i < $warmup; ++$i) {
        $check($kernel->handle($request));
    }

    $durations = [];
    for ($sample = 0; $sample < $samples; ++$sample) {
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; ++$i) {
            $check($kernel->handle($request));
        }
        $durations[] = (hrtime(true) - $start) / 1_000_000 / $iterations;
    }

    sort($durations, SORT_NUMERIC);
    $median = $durations[intdiv(count($durations), 2)];
    $p95Index = max(0, (int) ceil(count($durations) * 0.95) - 1);
    $p95 = $durations[$p95Index];

    return [
        'median_ms' => round($median, 6),
        'p95_ms' => round($p95, 6),
        'requests_per_second' => round(1000 / $median, 2),
        'sample_ms' => array_map(static fn (float $value): float => round($value, 6), $durations),
    ];
}

function newKernel(int $middlewareCount = 0): Kernel
{
    $kernel = new Kernel(new Application());

    for ($i = 0; $i < $middlewareCount; ++$i) {
        $kernel->middleware(new class implements Middleware {
            /** @param Closure(Request): Response $next */
            public function process(Request $request, Closure $next): Response
            {
                return $next($request);
            }
        });
    }

    return $kernel;
}

/** @return array{kernel: Kernel, request: Request, status: int} */
function scenario(string $name, int $size = 1, int $middlewareCount = 0): array
{
    $kernel = newKernel($middlewareCount);
    $ok = static fn (Request $request): Response => new Response(200, [], 'ok');

    if ($name === 'static' || $name === 'middleware') {
        $kernel->router()->get('/bench', $ok);
        return ['kernel' => $kernel, 'request' => new Request('GET', '/bench'), 'status' => 200];
    }

    if ($name === 'dynamic') {
        $kernel->router()->get('/users/{id}', static fn (Request $request): Response =>
            new Response(200, [], $request->routeParameter('id') ?? ''));
        return ['kernel' => $kernel, 'request' => new Request('GET', '/users/123'), 'status' => 200];
    }

    if ($name === 'scaling') {
        for ($i = 0; $i < $size; ++$i) {
            $kernel->router()->get('/route-' . $i, $ok);
        }
        return ['kernel' => $kernel, 'request' => new Request('GET', '/route-' . ($size - 1)), 'status' => 200];
    }

    if ($name === 'not_found') {
        $kernel->router()->get('/bench', $ok);
        return ['kernel' => $kernel, 'request' => new Request('GET', '/missing'), 'status' => 404];
    }

    if ($name === 'method_not_allowed') {
        $kernel->router()->get('/bench', $ok);
        return ['kernel' => $kernel, 'request' => new Request('POST', '/bench'), 'status' => 405];
    }

    throw new InvalidArgumentException('Unknown benchmark scenario: ' . $name);
}

$cases = [
    ['static', 1, 0],
    ['dynamic', 1, 0],
    ['middleware', 1, 1],
    ['middleware', 1, 3],
    ['middleware', 1, 5],
    ['scaling', 1, 0],
    ['scaling', 100, 0],
    ['scaling', 500, 0],
    ['scaling', 1000, 0],
    ['not_found', 1, 0],
    ['method_not_allowed', 1, 0],
];

$results = [];
foreach ($cases as [$name, $size, $middlewareCount]) {
    $label = match ($name) {
        'middleware' => 'middleware_' . $middlewareCount,
        'scaling' => 'routes_' . $size,
        default => $name,
    };

    $setupStart = hrtime(true);
    $case = scenario($name, $size, $middlewareCount);
    $setupMs = (hrtime(true) - $setupStart) / 1_000_000;
    $allocatedBefore = memory_get_usage(true);
    $measurement = measure($case['kernel'], $case['request'], $case['status'], $warmup, $iterations, $samples);
    $results[$label] = [
        'setup_ms' => round($setupMs, 4),
        'allocated_memory_bytes' => memory_get_usage(true),
        'allocated_delta_bytes' => memory_get_usage(true) - $allocatedBefore,
        ...$measurement,
    ];
    printf("%-23s median %10.6f ms  p95 %10.6f ms  %12.2f req/s\n",
        $label, $measurement['median_ms'], $measurement['p95_ms'], $measurement['requests_per_second']);
    unset($case);
}

$report = [
    'benchmark' => 'careminate_http_kernel_v1',
    'generated_at' => gmdate(DATE_ATOM),
    'environment' => [
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'php_os' => PHP_OS_FAMILY,
        'xdebug_loaded' => extension_loaded('xdebug'),
        'opcache_enabled_cli' => ini_get('opcache.enable_cli'),
        'memory_limit' => ini_get('memory_limit'),
        'peak_allocated_memory_bytes' => memory_get_peak_usage(true),
    ],
    'configuration' => compact('iterations', 'samples', 'warmup'),
    'results' => $results,
];

if ($output !== null) {
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($output, $json . PHP_EOL) === false) {
        throw new RuntimeException('Unable to write benchmark report: ' . $output);
    }
    fwrite(STDOUT, 'Report: ' . $output . PHP_EOL);
}
