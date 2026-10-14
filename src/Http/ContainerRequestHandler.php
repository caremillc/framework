<?php

declare(strict_types=1);

namespace Flint\Http;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use UnexpectedValueException;

final readonly class ContainerRequestHandler implements RequestHandler
{
    public function __construct(
        private ContainerInterface $container,
        private string $id,
    ) {
        if ($id === '') {
            throw new InvalidArgumentException(
                'A route handler service identifier must not be empty.',
            );
        }
    }

    public function handle(Request $request): Response
    {
        $handler = $this->container->get($this->id);

        if (!$handler instanceof RequestHandler) {
            throw new UnexpectedValueException(
                sprintf(
                    'Container entry "%s" must implement RequestHandler.',
                    $this->id,
                ),
            );
        }

        return $handler->handle($request);
    }
}
