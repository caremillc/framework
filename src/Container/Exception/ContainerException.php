<?php

declare(strict_types=1);

namespace Careminate\Container\Exception;

use Careminate\Exception\FrameworkException;
use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;
use Throwable;

/**
 * Common extension boundary for Careminate container failures.
 *
 * @api
 */
abstract class ContainerException extends FrameworkException implements ContainerExceptionInterface
{
    /**
     * @var list<string>
     */
    private readonly array $dependencyPath;

    /**
     * @param array<array-key, mixed> $dependencyPath
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        array $dependencyPath = [],
    ) {
        if (!array_is_list($dependencyPath)) {
            throw new InvalidArgumentException(
                'A dependency path must be a list of strings.',
            );
        }

        $validatedPath = [];

        foreach ($dependencyPath as $identifier) {
            if (!is_string($identifier)) {
                throw new InvalidArgumentException(
                    'A dependency path must contain only strings.',
                );
            }

            $validatedPath[] = $identifier;
        }

        $this->dependencyPath = $validatedPath;

        parent::__construct($message, $code, $previous);
    }

    /**
     * @return list<string>
     */
    public function dependencyPath(): array
    {
        return $this->dependencyPath;
    }
}
