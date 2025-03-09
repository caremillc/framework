<?php
namespace Careminate\Routing;

use Careminate\Logs\Log;
use Careminate\Http\Middlewares\Middleware;

class Router implements RouterInterface
{
    protected static $routes = [];
    protected static $groupAttributes = [];

    public function routes():array
    {
        return static::$routes;
    }

    public static function add(string $method, string $route, $controller, $action = null, array $middleware = [])
    {
         $route          = self::applyGroupPrefix($route);
         $middleware     = array_merge(static::getGroupMiddleware(), $middleware);
        
        self::$routes[] = [
            'method'     => $method,
            'uri'        => $route == '/' ? $route : ltrim($route, '/'),
            'controller' => $controller,
            'action'     => $action,
            'middleware' => $middleware,
        ];

    }

    public static function group($attributes, $callback): void
    {
        $previousGroupAttribute  = static::$groupAttributes;
        static::$groupAttributes = array_merge(static::$groupAttributes, $attributes);
        call_user_func($callback, new self);
        static::$groupAttributes = $previousGroupAttribute;
    }

    protected static function applyGroupPrefix($route): string
    {
        if (isset(static::$groupAttributes['prefix'])) {
            $full_route = rtrim(static::$groupAttributes['prefix'], '/') . '/' . ltrim($route, '/');
            // echo"<pre>";
            // var_dump($full_route);
            return rtrim(ltrim($full_route, '/'), '/');
        } else {
            return $route;
        }
    }
   
    protected static function getGroupMiddleware(): array
    {
        // echo"<pre>";
        // var_dump(static::$groupAttributes['middleware']?? []);
        return static::$groupAttributes['middleware'] ?? [];
    }
	

    public static function dispatch(string $uri, string $method)
    {
        // echo"<pre>";
        // var_dump(static::$routes);
        // exit;

        // Handle the favicon request early to avoid unnecessary processing
        if (self::handleFavicon($uri)) {
            return;
        }

        $uri = ltrim($uri, '/'); // Remove only the leading slash, not "/public/"
        $uri = empty($uri) ? '/' : $uri;
        $method = strtoupper($method);                        

        foreach (static::$routes as $route) {
            if ($route['method'] == $method) {
                $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[a-zA-Z0-9_]+)', $route['uri']);
                $pattern = "#^$pattern$#";

                if (preg_match($pattern, $uri, $matches)) {
                    $params     = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                    $controller = $route['controller'];

                    // Check if controller is a callable object or class
                    if (is_object($controller)) {
                        // Check if action exists in controller
                        $middlewareStack = ! empty($route['action']) && ! empty($route['middleware']) ?
                        array_merge($route['middleware'], $route['action']) : $route['middleware'];

                        // Call object directly
                        // Prepare Data and add anonymous function to $next variable
                        $next = function ($request) use ($controller, $params) {
                            return $controller(...$params);
                        };

                        $next = Middleware::handleMiddleware($middlewareStack, $next);
                        
                        echo $next($uri);
                    } else {
                        // Check if action exists in controller
                        $action          = $route['action'];
                        $middlewareStack = $route['middleware'];

                        //    var_dump($middlewareStack);

                        if (! method_exists($controller, $action)) {
                            throw new Log("Action '$action' not found in controller '$controller'.");
                        }

                        // Prepare Data and add anonymous function to $next variable
                        $next = function ($request) use ($controller, $action, $params) {
                            return call_user_func_array([new $controller, $action], $params);
                        };

                        $next = Middleware::handleMiddleware($middlewareStack, $next);
                        echo $next($uri);
                    }

                    return '';
                }
            }
            // echo "<pre>";
            // var_dump($key, $route);
        }

        throw new Log("This route '.$uri.' not found");
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
