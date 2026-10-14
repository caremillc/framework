<?php

declare(strict_types=1);

namespace Flint\Http;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use UnexpectedValueException;

final readonly class ContainerMiddleware implements Middleware
{
    public function __construct(
        private ContainerInterface $container,
        private string $id,
    ) {
        if ($id === '') {
            throw new InvalidArgumentException(
                'A middleware service identifier must not be empty.',
            );
        }
    }

    public function process(
        Request $request,
        RequestHandler $next,
    ): Response {
        $middleware = $this->container->get($this->id);

        if (!$middleware instanceof Middleware) {
            throw new UnexpectedValueException(
                sprintf(
                    'Container entry "%s" must implement Middleware.',
                    $this->id,
                ),
            );
        }

        return $middleware->process($request, $next);
    }
}
