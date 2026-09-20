<?php

declare(strict_types=1);

namespace Careminate\Module\Exception;

use Careminate\Exception\FrameworkException;

/**
 * Reports a module dependency-resolution failure.
 *
 * @api
 */
final class ModuleResolutionException extends FrameworkException
{
    /**
     * @var list<string>
     */
    private readonly array $modulePath;

    /**
     * @param list<string> $modulePath
     */
    public function __construct(
        string $message,
        array $modulePath = [],
    ) {
        parent::__construct($message);

        $this->modulePath = $modulePath;
    }

    /**
     * @return list<string>
     */
    public function modulePath(): array
    {
        return $this->modulePath;
    }
}
