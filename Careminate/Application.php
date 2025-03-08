<?php 
namespace Careminate;

use Careminate\Routing\Route;

class Application
{
    protected $router;

    public function start()
    {
        $router = new Route;

       // var_dump(config('route.path'));
        // Using require_once to avoid multiple inclusions
         require_once route_path('web.php');

         $router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
        
        echo"<pre>";
        var_dump($router->routes());

    }
	
}