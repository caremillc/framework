<?php
namespace Careminate;

use App\Http\Kernel;
use Careminate\Routing\Route;
use Careminate\Routing\Segment;

class Application
{
    protected $router;
    protected $frameworksetting;

    public function start():void
    {
        $this->router = new Route;
        // var_dump(Segment::get(1));
        // var_dump(Segment::all());
          //set timezone
          $this->frameworksetting = new FrameworkSetting;   
          $this->frameworksetting::setTimeZone(); 
        if(Segment::get(1) == 'api'){
            $this->apiRoute();
        }else{
            $this->webRoute();
        } 
    }

    
    public function webRoute()
    {
        foreach (Kernel::$globalWeb as $global) {
            new $global();
        }
        include route_path('web.php');
    }

    public function apiRoute()
    {
        foreach (Kernel::$globalApi as $global) {
            new $global();
        }
        include route_path('api.php');
    }

    public function __destruct()
    {
        $this->router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
    }

}
