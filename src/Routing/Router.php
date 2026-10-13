<?php

declare(strict_types=1);

namespace Flint\Routing;

use Closure;
use Flint\Http\Request;
use Flint\Http\RequestHandler;
use Flint\Http\Response;
use Flint\Http\ResponseFactory;
use InvalidArgumentException;
use UnexpectedValueException;

final class Router implements RequestHandler
{
    /**
     * @var array<string, array<string, Closure(Request): mixed>>
     */
    private array $routes = [];

    /**
     * @param callable(Request): mixed $handler
     */
    public function add(
        string $method,
        string $path,
        callable $handler,
    ): void {
        $registration = new Request(method: $method, target: $path);

        if ($registration->queryString() !== null) {
            throw new InvalidArgumentException(
                'Route paths must not contain a query string.',
            );
        }

        $key = 'm:' . $method;

        if (isset($this->routes[$path][$key])) {
            throw new InvalidArgumentException(
                sprintf(
                    'Route "%s %s" is already registered.',
                    $method,
                    $path,
                ),
            );
        }

        $this->routes[$path][$key] = Closure::fromCallable($handler);
    }

    public function handle(Request $request): Response
    {
        $routes = $this->routes[$request->path()] ?? null;

        if ($routes === null) {
            return ResponseFactory::text('Not found', status: 404);
        }

        $key = 'm:' . $request->method();

        if (isset($routes[$key])) {
            return self::invoke($routes[$key], $request);
        }

        if ($request->method() === 'HEAD' && isset($routes['m:GET'])) {
            return self::invoke($routes['m:GET'], $request);
        }

        $methods = [];

        foreach (array_keys($routes) as $methodKey) {
            $methods[] = substr($methodKey, 2);
        }

        if (isset($routes['m:GET']) && !isset($routes['m:HEAD'])) {
            $methods[] = 'HEAD';
        }

        sort($methods, SORT_STRING);

        $response = ResponseFactory::text(
            'Method not allowed',
            status: 405,
        );

        return $response->withHeaders(
            $response->headers()->with('Allow', implode(', ', $methods)),
        );
    }

    /** invoke
     * @param Closure(Request): mixed $handler
     */
    private static function invoke(
        Closure $handler,
        Request $request,
    ): Response {
        $response = $handler($request);

        if (!$response instanceof Response) {
            throw new UnexpectedValueException(
                'Route handlers must return a Response.',
            );
        }

        return $response;
    }
}
