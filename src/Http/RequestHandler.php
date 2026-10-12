<?php

declare(strict_types=1);

namespace Flint\Http;

interface RequestHandler
{
    public function handle(Request $request): Response;
}
