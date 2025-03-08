<?php
namespace Careminate\Http\Middlewares;

use App\Http\Kernel;
use Careminate\Routing\Segment;

class Middleware
{    
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
                $next = function ($request) use ($middleware, $next) {
                    $role       = explode(',', $middleware);
                    $middleware = array_shift($role);
                    if (! class_exists($middleware)) {
                        $middleware = self::getMiddlewareFromKernel($middleware);
                    }
                    return (new $middleware)->handle($request, $next, ...$role);
                };
            }
        }
        return $next;
    }
    
    /**
     * getMiddlewareFromKernel
     *
     * @param  mixed $key
     * @param  mixed $type
     * @return void
     */
    public static function getMiddlewareFromKernel($key)
    {
        $type    = Segment::get(1) == 'api' ? 'api' : 'web';    //this  code
        if ($type == 'web' && isset(Kernel::$middlewareWebRoute[$key])) {
            // var_dump(Kernel::$middlewareWebRoute[$key]);
            return Kernel::$middlewareWebRoute[$key];
        } elseif ($type == 'api' && isset(Kernel::$middlewareApiRoute[$key])) {
            // var_dump(Kernel::$middlewareApiRoute[$key]);
            return Kernel::$middlewareApiRoute[$key];
        } else {
            throw new \Exception('This Middleware (' . $key . ') Not Found ');
        }
    }
}
