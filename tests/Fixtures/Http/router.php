<?php

declare(strict_types=1);

use Flint\Http\Exception\RequestBodyTooLargeException;
use Flint\Http\Headers;
use Flint\Http\Output\PhpResponseOutput;
use Flint\Http\PhpRequestFactory;
use Flint\Http\Request;
use Flint\Http\RequestBodyReader;
use Flint\Http\Response;
use Flint\Http\ResponseEmitter;
use Flint\Http\ResponseFactory;
use Flint\Routing\Router;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

$token = getenv('FLINT_HTTP_SMOKE_TOKEN');

if ($token === false || $token === '') {
    throw new RuntimeException('The HTTP smoke token is missing.');
}

$emitter = new ResponseEmitter(new PhpResponseOutput());
$headRequest = ($_SERVER['REQUEST_METHOD'] ?? null) === 'HEAD';

try {
    $request = new PhpRequestFactory(
        bodyReader: new RequestBodyReader(maxBytes: 8),
    )->capture();
} catch (RequestBodyTooLargeException) {
    $emitter->emit(
        ResponseFactory::text('Too large', 413),
        headRequest: $headRequest,
    );

    return;
} catch (InvalidArgumentException) {
    $emitter->emit(
        ResponseFactory::text('Bad request', 400),
        headRequest: $headRequest,
    );

    return;
}

$router = new Router();

$router->add(
    'GET',
    '/ready',
    static fn (Request $request): Response => ResponseFactory::text($token),
);

$router->add(
    'GET',
    '/text',
    static fn (Request $request): Response => ResponseFactory::text("Caf\xC3\xA9"),
);

$router->add(
    'GET',
    '/json',
    static fn (Request $request): Response => ResponseFactory::json(['ready' => true]),
);

$router->add(
    'GET',
    '/zero',
    static fn (Request $request): Response => ResponseFactory::text('0'),
);

$router->add(
    'GET',
    '/cookies',
    static fn (Request $request): Response => new Response(
        headers: new Headers()->with(
            'Set-Cookie',
            'first=1; Path=/',
            'second=2; Path=/',
        ),
    ),
);

$router->add(
    'GET',
    '/location',
    static fn (Request $request): Response => new Response(
        status: 202,
        body: 'Accepted',
        headers: new Headers()->with('Location', '/next'),
    ),
);

$router->add(
    'GET',
    '/empty',
    static fn (Request $request): Response => new Response(
        status: 204,
        headers: new Headers()->with('Content-Length', '999'),
    ),
);

$router->add(
    'GET',
    '/reset',
    static fn (Request $request): Response => new Response(status: 205),
);

$router->add(
    'GET',
    '/cached',
    static fn (Request $request): Response => new Response(
        status: 304,
        headers: new Headers()
            ->with('ETag', '"example"')
            ->with('Content-Length', '999'),
    ),
);

$router->add(
    'POST',
    '/echo',
    static fn (Request $request): Response => new Response(
        body: $request->body(),
        headers: new Headers()
            ->with('Content-Type', 'application/octet-stream')
            ->with('X-Captured-Method', $request->method())
            ->with(
                'X-Captured-Content-Type',
                $request->headers()->get('Content-Type')[0] ?? '',
            ),
    ),
);

$router->add(
    'GET',
    '/head-explicit',
    static fn (Request $request): Response => new Response(
        body: 'Explicit',
        headers: new Headers()->with('X-Handler', 'get'),
    ),
);

$router->add(
    'HEAD',
    '/head-explicit',
    static fn (Request $request): Response => new Response(
        body: 'Explicit',
        headers: new Headers()->with('X-Handler', 'head'),
    ),
);

$response = $router->handle($request);

// Verify that emission removes previously queued PHP headers.
header('X-Stale: remove-me');

$emitter->emit(
    $response,
    headRequest: $request->method() === 'HEAD',
);
