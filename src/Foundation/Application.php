<?php

declare(strict_types=1);

namespace Flint\Foundation;

use Flint\Container\Container;
use InvalidArgumentException;

final class Application
{
    private readonly string $basePath;

    private readonly Container $container;

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
        $this->container = new Container();
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function container(): Container
    {
        return $this->container;
    }
}
