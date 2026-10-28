<?php

declare(strict_types=1);

namespace Careminate\Http\Routing;

use Careminate\Http\Middleware\Middleware;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Closure;
use InvalidArgumentException;

final readonly class Route
{
    public string $method;

    public string $path;

    /**
     * @var Closure(Request): Response
     */
    private Closure $handler;

    /**
     * @var list<Middleware>
     */
    private array $middleware;

    /**
     * @param Closure(Request): Response $handler
     * @param list<Middleware> $middleware
     */
    public function __construct(
        string $method,
        string $path,
        Closure $handler,
        array $middleware = [],
    ) {
        $normalizedMethod = strtoupper($method);

        if (
            preg_match(
                "/\A[!#$%&'*+.^_`|~0-9A-Za-z-]+\z/D",
                $method,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Invalid route method.',
            );
        }

        if (
            $path === ''
            || $path[0] !== '/'
            || str_contains($path, '?')
            || str_contains($path, '#')
            || preg_match('/[\x00-\x20\x7F]/', $path) === 1
        ) {
            throw new InvalidArgumentException(
                'Invalid route path.',
            );
        }

        foreach ($middleware as $item) {
            if (!$item instanceof Middleware) {
                throw new InvalidArgumentException(
                    'Route middleware must implement Middleware.',
                );
            }
        }

        $this->method = $normalizedMethod;
        $this->path = $path;
        $this->handler = $handler;
        $this->middleware = array_values($middleware);
    }

    public function run(Request $request): Response
    {
        $next = $this->handler;

        foreach (array_reverse($this->middleware) as $middleware) {
            $downstream = $next;

            $next = static function (
                Request $request,
            ) use ($middleware, $downstream): Response {
                return $middleware->process(
                    $request,
                    $downstream,
                );
            };
        }

        return $next($request);
    }
}
