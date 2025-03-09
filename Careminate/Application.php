<?php
namespace Careminate;

use App\Http\Kernel;
use Careminate\Routing\Route;
use Careminate\FrameworkSetting;
use Careminate\Http\Csrf\CsrfToken;
use Careminate\ServiceProviders\LocaleService;

class Application
{
    protected $router;
    protected $frameworksetting;
    protected LocaleService $localeService;

    public function __construct(LocaleService $localeService)
    {
        $this->localeService = $localeService;
    }

    public function start(): void
    {
        $this->router = new Route();
        $this->frameworksetting = new FrameworkSetting;
        $this->frameworksetting::setTimeZone();
        // Uncomment if you want locale set
        // $this->frameworksetting::setLocale(config('app.locale'));

        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        CsrfToken::createCsrf(); // Ensure CSRF token is set

        // Determine whether the request is for API or web
        strpos($uri, '/api') === 0 ? $this->apiRoute() : $this->webRoute();
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
        $this->router->dispatch(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $_SERVER['REQUEST_METHOD']);
    }
}
