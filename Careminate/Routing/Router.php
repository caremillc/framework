<?php
namespace Careminate\Routing;

class Router implements RouterInterface
{
    protected static $routes = [
        'GET'     => [],
        'POST'    => [],
        'PUT'     => [],
        'PATCH'   => [],
        'DELETE'  => [],
        'HEAD'    => [],
        'OPTIONS' => [],
    ];


    public static function add(string $method, string $route, $controller, $action = null, array $middleware = [])
    {
        $route                         = ltrim($route, '/'); // Ensure we only remove the leading slash
        self::$routes[$method][$route] = compact('controller', 'action', 'middleware');
    }

    public function routes():array
    {
        return static::$routes;
    }

    public static function dispatch(string $uri, string $method)
    {
        // Handle the favicon request early to avoid unnecessary processing
        if (self::handleFavicon($uri)) {
            return;
        }

        //$uri = ltrim($uri, '/' . static::public_path()); // Removes the /public/ prefix if it's part of the URI
        $uri = ltrim($uri, '/');                         // Remove only the leading slash, not "/public/"
                                                         

        foreach (static::$routes[$method] as $key => $val) {
            $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[a-zA-Z0-9_]+)', $key);
            $pattern = "#^$pattern$#";

            if (preg_match($pattern, $uri, $matches)) {
                $params     = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $controller = $val['controller'];

                // Check if controller is a callable object or class
                if (is_object($controller)) {
                    // Check if action exists in controller
                    $val['middleware'] = $val['action'];
                    $middlewareStack   = $val['middleware'];

                    // Call object directly
                    // Prepare Data and add anonymous function to $next variable
                    $next = function ($request) use ($controller, $params) {
                        return $controller(...$params);
                    };

                    $next = self::handleMiddleware($middlewareStack, $next);

                    return $next($uri);
                } else {
                    // Check if action exists in controller
                    $action          = $val['action'];
                    $middlewareStack = $val['middleware'];

                    //  var_dump($middlewareStack);

                    if (! method_exists($controller, $action)) {
                        throw new \Exception("Action '$action' not found in controller '$controller'.");
                    }

                    // Prepare Data and add anonymous function to $next variable
                    $next = function ($request) use ($controller, $action, $params) {
                        return call_user_func_array([new $controller, $action], $params);
                    };

                    $next = self::handleMiddleware($middlewareStack, $next);
                    
                    return $next($uri);
                }

                // return '';
            }
            // echo "<pre>";
            // var_dump($key, $val);
        }

        throw new \Exception("This route '.$uri.' not found");
    }

    /**
     * handleMiddleware
     *
     * @param  mixed $middlewareStack
     * @param  mixed $next
     * @return void
     */
    public static function handleMiddleware($middlewareStack, $next)
    {
        if (! empty($middlewareStack) && is_array($middlewareStack)) {
            foreach (array_reverse($middlewareStack) as $middleware) {

                var_dump($middlewareStack);

                $next = function ($request) use ($middleware, $next) {
                    $role       = explode(',', $middleware);
                    $middleware = array_shift($role);

                    //var_dump($role);
                    return (new $middleware)->handle($request, $next, $role);
                };
            }
        }
        return $next;
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
