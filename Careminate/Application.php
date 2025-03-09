<?php 
namespace Careminate;

use App\Http\Kernel;
use Careminate\Routing\Route;
use Careminate\Routing\Segment;
use Careminate\FrameworkSetting;
use Careminate\ServiceProviders\LocaleService;

class Application
{
    protected $router;
    protected $frameworksetting;
    protected LocaleService $localeService; // Add LocaleService dependency

    public function __construct(LocaleService $localeService)
    {
        $this->localeService = $localeService; // Inject the LocaleService
    }
    
    public function start(): void
    {
        $this->router = new Route();
        $this->frameworksetting = new FrameworkSetting;
        $this->frameworksetting::setTimeZone();

        $uri = parse_url($_SERVER['REQUEST_URI'])['path']; // Get the current URI directly

        // Check if the URI starts with '/api'
        if (strpos($uri, '/api') === 0) {
            $this->apiRoute(); // Load API-specific routes
        } else {
            $this->webRoute(); // Otherwise, load web routes
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
        include route_path('api.php'); // Include API-specific routes
    }

    public function __destruct()
    {
        // Dispatch the request to the appropriate controller and action
        $this->router->dispatch(parse_url($_SERVER['REQUEST_URI'])['path'], $_SERVER['REQUEST_METHOD']);
    }
}
