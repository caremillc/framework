<?php

declare(strict_types=1);

namespace Careminate\Http\Middleware;

use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Security\CsrfTokenManager;
use Closure;

final readonly class CsrfMiddleware implements Middleware
{
    /**
     * @param (Closure(Request): bool)|null $isExempt
     */
    public function __construct(
        private CsrfTokenManager $tokens,
        private ?Closure $isExempt = null,
    ) {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function process(
        Request $request,
        Closure $next,
    ): Response {
        // Only read-only HTTP methods are exempt by default.
        if (in_array(
            $request->method,
            ['GET', 'HEAD', 'OPTIONS'],
            true,
        )) {
            return $next($request);
        }

        // Application-defined exceptions must be explicit.
        if (
            $this->isExempt !== null
            && ($this->isExempt)($request)
        ) {
            return $next($request);
        }

        if (! $this->tokens->verify(
            $this->submittedToken($request)
        )) {
            return new Response(
                403,
                [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Cache-Control' => 'no-store',
                ],
                'Invalid CSRF token.',
            );
        }

        return $next($request);
    }

    private function submittedToken(Request $request): mixed
    {
        $header = $request->header('X-CSRF-Token');

        if ($header !== null) {
            return $header;
        }

        $contentType = strtolower(trim(
            explode(
                ';',
                $request->header('Content-Type') ?? '',
                2,
            )[0]
        ));

        if ($contentType !== 'application/x-www-form-urlencoded') {
            return null;
        }

        $fields = [];

        parse_str($request->body, $fields);

        return $fields['_token'] ?? null;
    }
}
