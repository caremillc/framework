<?php 
namespace Careminate\Routing;

interface RouterInterface
{
    /**
     * Add a route to the router.
     *
     * @param string $method HTTP method (GET, POST, PUT, etc.)
     * @param string $route The route pattern.
     * @param mixed $controller The controller to handle the route.
     * @param mixed $action The action within the controller.
     * @param array $middleware The middleware for this route.
     * 
     * @return void
     */
    public static function add(string $method, string $route, $controller, $action = null, array $middleware = []);

    /**
     * Retrieve all the registered routes.
     *
     * @return array
     */
    public function routes(): array;

    /**
     * Dispatch the request to the appropriate controller and action.
     *
     * @param string $uri The URI to match against.
     * @param string $method The HTTP method (GET, POST, etc.)
     * 
     * @return mixed
     * 
     * @throws \Exception If no matching route is found.
     */
    public static function dispatch(string $uri, string $method);
}
 