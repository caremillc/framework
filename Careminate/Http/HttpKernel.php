<?php 
namespace Careminate\Http;

use Careminate\Http\Requests\Request;
use Careminate\Http\Responses\Response;
use Careminate\Routing\RouterInterface;

class HttpKernel extends ExtendedHttpKernel
{

    public function __construct(private RouterInterface $router)
    {
    }
    
    public function handle(Request $request): Response
    {
        $content = '';

        return new Response($content);
    }
    // Additional methods for the HttpKernel can be added here
}
