<?php

declare(strict_types=1);

namespace Careminate\Http\Middleware;

use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Session\SessionManager;
use Closure;

final readonly class SessionMiddleware implements Middleware
{
    public function __construct(
        private SessionManager $sessions,
    ) {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function process(
        Request $request,
        Closure $next,
    ): Response {
        $this->sessions->start();

        try {
            return $next($request);
        } finally {
            if ($this->sessions->isActive()) {
                $this->sessions->close();
            }
        }
    }
}
