<?php
namespace Careminate;

use App\Http\Kernel;
use Careminate\Routing\Route;
use Careminate\Routing\Segment;

class Application
{
    protected $router;
    protected $frameworksetting;

    public function start(): void
    {
        $this->router           = new Route;
        $this->frameworksetting = new FrameworkSetting;
        $this->frameworksetting::setTimeZone();
        if (parse_url(Segment::get(1))['path'] == 'api') {
            $this->apiRoute();
        } else {
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
        $this->router->dispatch(parse_url($_SERVER['REQUEST_URI'])['path'], $_SERVER['REQUEST_METHOD']);
    }

}
