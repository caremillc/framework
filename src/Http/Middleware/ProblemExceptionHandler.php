<?php

declare(strict_types=1);

namespace Careminate\Http\Middleware;

use Careminate\Http\Exception\HttpException;
use Careminate\Http\Exception\ProblemResponseFactory;
use Careminate\Http\Exception\ValidationProblemResponseFactory;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Validation\Exception\ValidationException;
use Closure;
use Throwable;

/**
 * Opt-in JSON error middleware for exceptions raised after request creation.
 */
final class ProblemExceptionHandler implements Middleware
{
    private readonly ProblemResponseFactory $responses;

    public function __construct(
        ?ProblemResponseFactory $responses = null,
    ) {
        $this->responses = $responses
            ?? new ProblemResponseFactory();
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function process(
        Request $request,
        Closure $next,
    ): Response {
        try {
            return $next($request);
        } catch (ValidationException $exception) {
            return new ValidationProblemResponseFactory()->create($exception);
        } catch (HttpException $exception) {
            return $this->responses->create($exception->status);
        } catch (Throwable) {
            return $this->responses->create(500);
        }
    }
}
