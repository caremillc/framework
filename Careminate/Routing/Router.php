<?php
// File: Careminate/Routing/Router.php

namespace Careminate\Routing;

use Careminate\Logs\Log;
use Careminate\Http\Response;
use Careminate\Http\Middlewares\Middleware;

class Router implements RouterInterface
{
    protected static $routes = [];
    protected static $groupAttributes = [];

    public function routes(): array
    {
        return static::$routes;
    }

    // public static function add(string $method, string $route, $controller, $action = null, array $middleware = [])
    // {
    //     $route = self::applyGroupPrefix($route);
    //     $middleware = array_merge(static::getGroupMiddleware(), $middleware);

    //     self::$routes[] = [
    //         'method'     => $method,
    //         'uri'        => $route == '/' ? $route : ltrim($route, '/'),
    //         'controller' => $controller,
    //         'action'     => $action,
    //         'middleware' => $middleware,
    //     ];
    // }

    public static function add(string $method, string $route, $controller, $action = null, array $middleware = [])
{
    // Handle controller defined as an array [Controller::class, 'action']
    if (is_array($controller) && count($controller) === 2) {
        list($controllerClass, $controllerAction) = $controller;
        $controller = $controllerClass;
        $action = $controllerAction;
    }

    $route = self::applyGroupPrefix($route);
    $middleware = array_merge(static::getGroupMiddleware(), $middleware);

    self::$routes[] = [
        'method'     => $method,
        'uri'        => $route === '/' ? $route : ltrim($route, '/'),
        'controller' => $controller,
        'action'     => $action,
        'middleware' => $middleware,
    ];
}

    public static function group($attributes, $callback): void
    {
        $previousGroupAttributes = static::$groupAttributes;
        static::$groupAttributes = array_merge(static::$groupAttributes, $attributes);
        call_user_func($callback, new self);
        static::$groupAttributes = $previousGroupAttributes;
    }

    // protected static function applyGroupPrefix($route): string
    // {
    //     if (isset(static::$groupAttributes['prefix'])) {
    //         return rtrim(static::$groupAttributes['prefix'], '/') . '/' . ltrim($route, '/');
    //     }
    //     return $route;
    // }

    protected static function applyGroupPrefix($route): string
{
    if (isset(static::$groupAttributes['prefix'])) {
        $prefix = rtrim(static::$groupAttributes['prefix'], '/');
        $routePart = ltrim($route, '/');

        // Handle the root route correctly
        if ($route === '/') {
            $routePart = '';
        }

        $combined = $prefix . ($routePart !== '' ? '/' . $routePart : '');
        return $combined === '' ? '/' : $combined;
    }
    return $route;
}

    protected static function getGroupMiddleware(): array
    {
        return static::$groupAttributes['middleware'] ?? [];
    }

    public static function dispatch(string $uri, string $method)
    {
        // Strip leading slashes to match the route correctly
        // dd($uri);
        $uri = ltrim($uri, '/');
        $uri = empty($uri) ? '/' : $uri;

        // Loop through routes and match the URI
        foreach (static::$routes as $route) {
            if ($route['method'] == strtoupper($method)) {
                // Use regex to match dynamic route parameters
                $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[a-zA-Z0-9_]+)', $route['uri']);
                $pattern = "#^$pattern$#";

                if (preg_match($pattern, $uri, $matches)) {
                    $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                    $controller = $route['controller'];

                    // Handle closures
                    if ($controller instanceof \Closure) {
                        $middlewareStack = array_merge($route['middleware'], $route['action'] ?? []);
                        $next = function ($uri) use ($controller, $params) {
                            echo $controller(...$params);
                        };
                        $next = Middleware::handleMiddleware($middlewareStack, $next);
                        return $next($uri);
                    }

                    // Handle class-based controllers
                    $action = $route['action'];
                    $middlewareStack = $route['middleware'];

                    if (! method_exists($controller, $action)) {
                        throw new Log("Action '$action' not found in controller '$controller'.");
                    }

                    // Prepare the next middleware call
                    $next = function ($uri) use ($controller, $action, $params) {
                        echo call_user_func_array([new $controller, $action], $params);
                    };

                    $next = Middleware::handleMiddleware($middlewareStack, $next);
                    return $next($uri);
                }
            }
        }

        // If no matching route found
        throw new Log("Route not found: $uri");
    }
}
