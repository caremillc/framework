<?php

declare(strict_types=1);

namespace Careminate\Http\Middleware;

use Careminate\Http\Request;
use Careminate\Http\Response;
use Closure;

interface Middleware
{
    /**
     * @param Closure(Request): Response $next
     */
    public function process(
        Request $request,
        Closure $next,
    ): Response;
}
