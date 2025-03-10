<?php 
namespace Careminate\Http;

use Careminate\Http\Requests\Request;
use Careminate\Http\Responses\Response;

class HttpKernel extends ExtendedHttpKernel
{
    public function handle(Request $request): Response
    {
        $content = '<h1>Hello World from HttpKernel</h1>';

        return new Response($content);
    }
    // Additional methods for the HttpKernel can be added here
}
