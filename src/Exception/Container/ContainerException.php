<?php

declare(strict_types=1);

namespace Careminate\Exception\Container;

use Careminate\Exception\RuntimeException;
use Psr\Container\ContainerExceptionInterface;
use Throwable;

class ContainerException extends RuntimeException implements ContainerExceptionInterface
{
    /**
     * @param list<string> $resolutionPath
     */
    public function __construct(
        string $message,
        private readonly array $resolutionPath = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return list<string>
     */
    public function resolutionPath(): array
    {
        return $this->resolutionPath;
    }
}
