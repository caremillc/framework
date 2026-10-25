<?php

declare (strict_types=1);

namespace Careminate\Http\Routing;

use Careminate\Http\Request;
use Careminate\Http\Response;
use Closure;
use LogicException;

final class Router
{
    /**
     * @var array<string, array<string, Route>>
     */
    private array $routes = [];

    /**
     * @var array<string, RoutePattern>
     */
    private array $patterns = [];

    private bool $frozen = false;

    /**
     * @param Closure(Request): Response $handler
     * @param array<string, string> $constraints
     */
    public function add(
        string $method,
        string $path,
        Closure $handler,
        array $constraints = [],
    ): void {
        if ($this->frozen) {
            throw new LogicException(
                'Cannot register routes after the router is frozen.',
            );
        }

        $route = new Route($method, $path, $handler);

        if (isset($this->routes[$route->path][$route->method])) {
            throw new LogicException(
                sprintf(
                    'Route "%s %s" is already registered.',
                    $route->method,
                    $route->path,
                ),
            );
        }

        $pattern = new RoutePattern($route->path, $constraints);

        $this->routes[$route->path][$route->method] = $route;

        if ($pattern->isDynamic()) {
            $this->patterns[$route->path] = $pattern;
        }
    }

    /**
     * @param Closure(Request): Response $handler
     * @param array<string, string> $constraints
     */
    public function get(
        string $path,
        Closure $handler,
        array $constraints = [],
    ): void {
        $this->add('GET', $path, $handler, $constraints);
    }

    /**
     * @param Closure(Request): Response $handler
     * @param array<string, string> $constraints
     */
    public function post(
        string $path,
        Closure $handler,
        array $constraints = [],
    ): void {
        $this->add('POST', $path, $handler, $constraints);
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    public function dispatch(Request $request): Response
    {
        $path    = $request->path();
        $allowed = [];

        // 1. Exact routes have highest precedence.
        if (isset($this->routes[$path])) {
            $exactRoutes = $this->routes[$path];

            if (isset($exactRoutes[$request->method])) {
                return $exactRoutes[$request->method]->run(
                    $request->withRouteParameters([]),
                );
            }

            foreach (array_keys($exactRoutes) as $method) {
                $allowed[$method] = true;
            }
        }

        // 2. Match registered dynamic routes.
        $matches = [];

        foreach ($this->patterns as $registeredPath => $pattern) {
            $parameters = $pattern->match($path);

            if ($parameters === null) {
                continue;
            }

            $matches[] = [
                'path'       => $registeredPath,
                'pattern'    => $pattern,
                'parameters' => $parameters,
            ];
        }

        usort(
            $matches,
            static function (array $left, array $right): int {
                $literalComparison =
                $right['pattern']->literalSegmentCount()
                <=> $left['pattern']->literalSegmentCount();

                if ($literalComparison !== 0) {
                    return $literalComparison;
                }

                return $right['pattern']->constrainedParameterCount()
                <=> $left['pattern']->constrainedParameterCount();
            },
        );

        foreach ($matches as $match) {
            $dynamicRoutes = $this->routes[$match['path']];

            if (isset($dynamicRoutes[$request->method])) {
                return $dynamicRoutes[$request->method]->run(
                    $request->withRouteParameters(
                        $match['parameters'],
                    ),
                );
            }

            foreach (array_keys($dynamicRoutes) as $method) {
                $allowed[$method] = true;
            }
        }

        // 3. Path matched, but not the requested method.
        if ($allowed !== []) {
            $methods = array_keys($allowed);
            sort($methods, SORT_STRING);

            return new Response(
                405,
                [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Allow'        => implode(', ', $methods),
                ],
                'Method Not Allowed',
            );
        }

        // 4. No registered route matched the path.
        return new Response(
            404,
            ['Content-Type' => 'text/plain; charset=utf-8'],
            'Not Found',
        );
    }

    public function count(): int
    {
        $count = 0;

        foreach ($this->routes as $routes) {
            $count += count($routes);
        }

        return $count;
    }
}
