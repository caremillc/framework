<?php
// File: Careminate/Routing/Router.php

namespace Careminate\Routing;

use Careminate\Logs\Log;
use Careminate\Http\Requests\Request;
use Careminate\Http\Middlewares\Middleware;

class Router implements RouterInterface
{
    protected static $routes          = [];
    protected static $groupAttributes = [];

    public function routes(): array
    {
        return static::$routes;
    }


    public static function add(string $method, string $route, $controller, $action = null, array $middleware = [])
    {
        // Handle controller defined as an array [Controller::class, 'action']
        if (is_array($controller) && count($controller) === 2) {
            list($controllerClass, $controllerAction) = $controller;
            $controller                               = $controllerClass;
            $action                                   = $controllerAction;
        }

        $route      = self::applyGroupPrefix($route);
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


    protected static function applyGroupPrefix($route): string
    {
        if (isset(static::$groupAttributes['prefix'])) {
            $prefix    = rtrim(static::$groupAttributes['prefix'], '/');
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

          // Handle the favicon request early to avoid unnecessary processing
          if (self::handleFavicon($uri)) {
            return;
        }


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
                    $params     = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                    $controller = $route['controller'];

                    // Handle closures
                    if ($controller instanceof \Closure) {
                        $middlewareStack = array_merge($route['middleware'], $route['action'] ?? []);
                        $next            = function ($uri) use ($controller, $params) {
                            echo $controller(...$params);
                        };
                        $next = Middleware::handleMiddleware($middlewareStack, $next);
                        return $next($uri);
                    }

                    // Handle class-based controllers
                    // Handle class-based controllers
                $action = $route['action'];
                $middlewareStack = $route['middleware'];

                if (!method_exists($controller, $action)) {
                    throw new Log("Action '$action' not found in controller '$controller'.");
                }
                    // Use reflection to resolve method parameters
                $reflectionMethod = new \ReflectionMethod($controller, $action);
                $resolvedParams = self::resolveMethodParameters($reflectionMethod, $params);

                // Prepare the next middleware call
                $next = function ($uri) use ($controller, $action, $resolvedParams) {
                    echo call_user_func_array([new $controller, $action], $resolvedParams);
                };

                $next = Middleware::handleMiddleware($middlewareStack, $next);
                return $next($uri);
                }
            }
        }

        // If no matching route found
        throw new Log("Route not found: $uri");
    }

     /**
     * Resolve method parameters, injecting Request where required.
     */
    protected static function resolveMethodParameters(\ReflectionMethod $method, array $routeParams): array
    {
        $parameters = [];
        foreach ($method->getParameters() as $param) {
            $paramType = $param->getType();
            if ($paramType && !$paramType->isBuiltin()) {
                $paramClass = $paramType->getName();
                if ($paramClass === Request::class || is_subclass_of($paramClass, Request::class)) {
                    // Inject the Request instance
                    $parameters[] = new Request();
                } else {
                    // Handle other dependencies if possible
                    throw new Log("Cannot resolve dependency {$paramClass}.");
                }
            } else {
                // Get route parameter by name
                $paramName = $param->getName();
                if (array_key_exists($paramName, $routeParams)) {
                    $parameters[] = $routeParams[$paramName];
                } elseif ($param->isDefaultValueAvailable()) {
                    // Use default value if available
                    $parameters[] = $param->getDefaultValue();
                } else {
                    throw new Log("Missing required parameter '{$paramName}' for {$method->class}::{$method->name}()");
                }
            }
        }
        return $parameters;
    }
    
     /**
     * Handle the favicon.ico request
     *
     * @param string $uri
     * @return bool
     */
    private static function handleFavicon(string $uri)
    {
        if ($uri === 'favicon.ico') {
            $faviconPath = ROOT_DIR . '/favicon.ico'; // Ensure ROOT_DIR is defined in your project
            if (file_exists($faviconPath)) {
                header('Content-Type: image/x-icon');
                header('Cache-Control: public, max-age=86400'); // Cache for 24 hours
                readfile($faviconPath);
                exit;
            } else {
                header("HTTP/1.1 404 Not Found");
                echo "Favicon not found";
                exit;
            }
        }
        return false; // Return false if the request is not for favicon.ico
    }
}
