<?php

declare(strict_types=1);

namespace Careminate\Http\Middleware;

use Careminate\Http\Exception\HttpException;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Closure;
use Throwable;

final class ExceptionHandler implements Middleware
{
    /**
     * @param Closure(Request): Response $next
     */
    public function process(
        Request $request,
        Closure $next,
    ): Response {
        try {
            return $next($request);
        } catch (HttpException $exception) {
            return $this->errorResponse(
                $exception->status,
            );
        } catch (Throwable) {
            return $this->errorResponse(500);
        }
    }

    private function errorResponse(int $status): Response
    {
        return new Response(
            $status,
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            $this->reasonPhrase($status),
        );
    }

    private function reasonPhrase(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            408 => 'Request Timeout',
            409 => 'Conflict',
            410 => 'Gone',
            413 => 'Content Too Large',
            415 => 'Unsupported Media Type',
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            default => $status >= 500
                ? 'Server Error'
                : 'Client Error',
        };
    }
}
