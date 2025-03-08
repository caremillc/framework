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

    /**
     * Implement the `routes()` method from `RouterInterface`.
     *
     * @return array
     */
    public function routes(): array
    {
        return static::$routes;
    }

    public static function dispatch(string $uri, string $method)
    {
         $uri = ltrim($uri, '/');  // Remove only the leading slash

        foreach (static::$routes[$method] as $key => $val) {
            $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[a-zA-Z0-9_]+)', $key);
            $pattern = "#^$pattern$#";
            if (preg_match($pattern, $uri, $matches)) {
                $controller = $val['controller'];
                $action     = $val['action'];
                $middleware = $val['middleware'];
                $params     = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return call_user_func_array([new $controller, $action], $params);
            }

        }

        throw new \Exception("'this route ' . $uri . ' not found'");
    }
}
