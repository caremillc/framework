<?php

declare(strict_types=1);

namespace Flint\Http;

interface Middleware
{
    public function process(Request $request, RequestHandler $next): Response;
}
