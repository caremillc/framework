<?php 
namespace Careminate\Routing;

use Careminate\Http\Requests\Request;

interface RouterInterface
{
    public function dispatch(Request $request);
}
