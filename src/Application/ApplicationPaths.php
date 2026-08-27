<?php

declare(strict_types=1);

namespace Careminate\Application;

use Careminate\Exception\Application\InvalidApplicationPathException;
use Careminate\Support\Path;

final readonly class ApplicationPaths
{
    /**
     * @param array<string, string> $paths
     */
    private function __construct(
        private string $basePath,
        private array $paths,
    ) {
    }

    /**
     * Override keys use ApplicationPath enum values.
     *
     * @param array<string, string> $overrides
     */
    public static function fromBasePath(
        string $basePath,
        array $overrides = [],
    ): self {
        if (!Path::isAbsolute($basePath)) {
            throw InvalidApplicationPathException::baseMustBeAbsolute($basePath);
        }

        $basePath = Path::normalize($basePath);

        $paths = [
            ApplicationPath::App->value => Path::join($basePath, 'app'),
            ApplicationPath::Bootstrap->value => Path::join($basePath, 'bootstrap'),
            ApplicationPath::Config->value => Path::join($basePath, 'config'),
            ApplicationPath::Public->value => Path::join($basePath, 'public'),
            ApplicationPath::Resources->value => Path::join($basePath, 'resources'),
            ApplicationPath::Routes->value => Path::join($basePath, 'routes'),
            ApplicationPath::Storage->value => Path::join($basePath, 'storage'),
            ApplicationPath::Cache->value => Path::join(
                $basePath,
                'bootstrap',
                'cache',
            ),
        ];

        foreach ($overrides as $name => $path) {
            $type = ApplicationPath::tryFrom($name);

            if ($type === null) {
                throw InvalidApplicationPathException::unknownPath($name);
            }

            $paths[$type->value] = Path::isAbsolute($path)
                ? Path::normalize($path)
                : Path::join($basePath, $path);
        }

        return new self($basePath, $paths);
    }

    public function base(): string
    {
        return $this->basePath;
    }

    public function path(ApplicationPath $path): string
    {
        return $this->paths[$path->value];
    }

    public function app(): string
    {
        return $this->path(ApplicationPath::App);
    }

    public function bootstrap(): string
    {
        return $this->path(ApplicationPath::Bootstrap);
    }

    public function config(): string
    {
        return $this->path(ApplicationPath::Config);
    }

    public function public(): string
    {
        return $this->path(ApplicationPath::Public);
    }

    public function resources(): string
    {
        return $this->path(ApplicationPath::Resources);
    }

    public function routes(): string
    {
        return $this->path(ApplicationPath::Routes);
    }

    public function storage(): string
    {
        return $this->path(ApplicationPath::Storage);
    }

    public function cache(): string
    {
        return $this->path(ApplicationPath::Cache);
    }

    public function containerCache(): string
    {
        return Path::join($this->cache(), 'container.php');
    }

    public function with(ApplicationPath $type, string $path): self
    {
        $paths = $this->paths;

        $paths[$type->value] = Path::isAbsolute($path)
            ? Path::normalize($path)
            : Path::join($this->basePath, $path);

        return new self($this->basePath, $paths);
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->paths;
    }
}
