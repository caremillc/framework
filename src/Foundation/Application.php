<?php

declare(strict_types=1);

namespace Flint\Foundation;

use Flint\Contracts\Foundation\ApplicationInterface;
use InvalidArgumentException;

final class Application implements ApplicationInterface
{
    private readonly string $basePath;

    public function __construct(string $basePath)
    {
        if ($basePath === '' || str_contains($basePath, "\0")) {
            throw new InvalidArgumentException(
                'The application base path must be a non-empty directory path without null bytes.',
            );
        }

        $resolvedPath = realpath($basePath);

        if ($resolvedPath === false || !is_dir($resolvedPath)) {
            throw new InvalidArgumentException(
                'The application base path must reference an existing directory.',
            );
        }

        $this->basePath = $resolvedPath;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }
}
