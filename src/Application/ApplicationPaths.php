<?php

declare(strict_types=1);

namespace Careminate\Application;

use Careminate\Application\Exception\InvalidApplicationInputException;

/**
 * Resolves application-relative paths against an explicit canonical root.
 *
 * @api
 */
final readonly class ApplicationPaths
{
    private string $base;

    public function __construct(string $baseDirectory)
    {
        if (
            $baseDirectory === ''
            || str_contains($baseDirectory, "\0")
            || !self::isAbsolute($baseDirectory)
        ) {
            throw new InvalidApplicationInputException(
                'The application root must be an absolute directory path.',
            );
        }

        $resolved = realpath($baseDirectory);

        if ($resolved === false || !is_dir($resolved)) {
            throw new InvalidApplicationInputException(
                'The application root must be an existing directory.',
            );
        }

        $this->base = $resolved;
    }

    public function base(): string
    {
        return $this->base;
    }

    public function path(string $relative = ''): string
    {
        if ($relative === '') {
            return $this->base;
        }

        $normalized = str_replace('\\', '/', $relative);

        if (preg_match('/[\x00-\x1F\x7F:]/', $normalized) === 1) {
            throw new InvalidApplicationInputException(
                'The relative application path contains unsupported characters.',
            );
        }

        $segments = explode('/', $normalized);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidApplicationInputException(
                    'The relative application path must contain non-empty child segments.',
                );
            }
        }

        return rtrim($this->base, '/\\')
            . DIRECTORY_SEPARATOR
            . implode(DIRECTORY_SEPARATOR, $segments);
    }

    private static function isAbsolute(string $path): bool
    {
        if (DIRECTORY_SEPARATOR === '/') {
            return str_starts_with($path, '/');
        }

        $normalized = str_replace('\\', '/', $path);

        return preg_match(
            '~\A(?:[A-Za-z]:/|//[^/]+/[^/]+(?:/|$))~',
            $normalized,
        ) === 1;
    }
}
