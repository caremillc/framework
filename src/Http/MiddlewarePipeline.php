<?php

declare(strict_types=1);

namespace Flint\Http;

final readonly class MiddlewarePipeline implements RequestHandler
{
    private RequestHandler $handler;

    public function __construct(
        RequestHandler $destination,
        Middleware ...$middleware,
    ) {
        $handler = $destination;

        foreach (array_reverse($middleware) as $layer) {
            $handler = new class ($layer, $handler) implements RequestHandler {
                public function __construct(
                    private readonly Middleware $middleware,
                    private readonly RequestHandler $next,
                ) {
                }

                public function handle(Request $request): Response
                {
                    return $this->middleware->process($request, $this->next);
                }
            };
        }

        $this->handler = $handler;
    }

    public function handle(Request $request): Response
    {
        return $this->handler->handle($request);
    }
}
