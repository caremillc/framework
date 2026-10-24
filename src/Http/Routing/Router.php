<?php

declare(strict_types=1);

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

    private bool $frozen = false;

    /**
     * @param Closure(Request): Response $handler
     */
    public function add(
        string $method,
        string $path,
        Closure $handler,
    ): void {
        if ($this->frozen) {
            throw new LogicException(
                'Cannot register routes after the router is frozen.'
            );
        }

        $route = new Route($method, $path, $handler);

        if (isset($this->routes[$route->path][$route->method])) {
            throw new LogicException(
                sprintf(
                    'Route "%s %s" is already registered.',
                    $route->method,
                    $route->path
                )
            );
        }

        $this->routes[$route->path][$route->method] = $route;
    }

    /**
     * @param Closure(Request): Response $handler
     */
    public function get(string $path, Closure $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /**
     * @param Closure(Request): Response $handler
     */
    public function post(string $path, Closure $handler): void
    {
        $this->add('POST', $path, $handler);
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
        $path = $request->path();

        if (!isset($this->routes[$path])) {
            return new Response(
                404,
                ['Content-Type' => 'text/plain; charset=utf-8'],
                'Not Found'
            );
        }

        $routes = $this->routes[$path];

        if (isset($routes[$request->method])) {
            return $routes[$request->method]->run($request);
        }

        $allowed = array_keys($routes);
        sort($allowed, SORT_STRING);

        return new Response(
            405,
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Allow' => implode(', ', $allowed),
            ],
            'Method Not Allowed'
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
