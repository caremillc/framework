<?php

declare(strict_types=1);

namespace Careminate\Support;

use Careminate\Exception\InvalidArgumentException;

final class Path
{
    private function __construct()
    {
    }

    public static function normalize(string $path): string
    {
        if ($path === '') {
            throw new InvalidArgumentException('A path must not be empty.');
        }

        $path = str_replace('\\', '/', $path);
        $parts = self::extractPrefix($path);

        $segments = [];

        foreach (explode('/', $parts['remainder']) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                $lastSegment = $segments === []
                    ? null
                    : $segments[array_key_last($segments)];

                if (
                    count($segments) > $parts['protectedSegments']
                    && $lastSegment !== '..'
                ) {
                    array_pop($segments);

                    continue;
                }

                if (!$parts['absolute']) {
                    $segments[] = '..';
                }

                continue;
            }

            $segments[] = $segment;
        }

        if ($parts['prefix'] === '//' && count($segments) < 2) {
            throw new InvalidArgumentException(
                'A UNC path must contain both a server and a share name.',
            );
        }

        $normalized = implode('/', $segments);

        if ($parts['prefix'] === '/') {
            return $normalized === '' ? '/' : '/' . $normalized;
        }

        if ($parts['prefix'] === '//') {
            return '//' . $normalized;
        }

        if ($parts['prefix'] !== '') {
            return $normalized === ''
                ? $parts['prefix'] . '/'
                : $parts['prefix'] . '/' . $normalized;
        }

        return $normalized === '' ? '.' : $normalized;
    }

    public static function join(string ...$parts): string
    {
        if ($parts === []) {
            throw new InvalidArgumentException(
                'At least one path part is required.',
            );
        }

        $result = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if ($result === '') {
                $result = $part;

                continue;
            }

            $separator = str_ends_with($result, '/')
                || str_ends_with($result, '\\')
                    ? ''
                    : '/';

            $result .= $separator . ltrim($part, '/\\');
        }

        if ($result === '') {
            throw new InvalidArgumentException(
                'At least one non-empty path part is required.',
            );
        }

        return self::normalize($result);
    }

    public static function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (
            str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
        ) {
            return true;
        }

        return preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    /**
     * Determine whether a candidate path is located inside an absolute base.
     *
     * This method performs lexical normalization and does not resolve symbolic
     * links. Security-sensitive filesystem operations should additionally use
     * realpath() after confirming that the relevant paths exist.
     */
    public static function isWithin(string $base, string $candidate): bool
    {
        if (!self::isAbsolute($base)) {
            throw new InvalidArgumentException(
                sprintf('The base path "%s" must be absolute.', $base),
            );
        }

        $normalizedBase = self::normalize($base);

        $normalizedCandidate = self::isAbsolute($candidate)
            ? self::normalize($candidate)
            : self::join($normalizedBase, $candidate);

        $comparableBase = self::comparisonValue($normalizedBase);
        $comparableCandidate = self::comparisonValue($normalizedCandidate);

        if ($comparableBase === '/') {
            return str_starts_with($comparableCandidate, '/');
        }

        $comparableBase = rtrim($comparableBase, '/');

        return $comparableCandidate === $comparableBase
            || str_starts_with(
                $comparableCandidate,
                $comparableBase . '/',
            );
    }

    /**
     * @return array{
     *     prefix: string,
     *     remainder: string,
     *     absolute: bool,
     *     protectedSegments: int
     * }
     */
    private static function extractPrefix(string $path): array
    {
        if (preg_match('/^[A-Za-z]:\//', $path) === 1) {
            return [
                'prefix' => strtoupper($path[0]) . ':',
                'remainder' => substr($path, 3),
                'absolute' => true,
                'protectedSegments' => 0,
            ];
        }

        if (str_starts_with($path, '//')) {
            return [
                'prefix' => '//',
                'remainder' => ltrim($path, '/'),
                'absolute' => true,
                'protectedSegments' => 2,
            ];
        }

        if (str_starts_with($path, '/')) {
            return [
                'prefix' => '/',
                'remainder' => ltrim($path, '/'),
                'absolute' => true,
                'protectedSegments' => 0,
            ];
        }

        return [
            'prefix' => '',
            'remainder' => $path,
            'absolute' => false,
            'protectedSegments' => 0,
        ];
    }

    private static function comparisonValue(string $path): string
    {
        if (
            preg_match('/^[A-Za-z]:\//', $path) === 1
            || str_starts_with($path, '//')
        ) {
            return strtolower($path);
        }

        return $path;
    }
}
