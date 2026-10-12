<?php

declare(strict_types=1);

namespace Flint\Http;

use InvalidArgumentException;

final class ServerRequestFactory
{
    /**
     * @param array<array-key, mixed> $server
     */
    public function create(array $server, string $body = ''): Request
    {
        $method = self::requiredString($server, 'REQUEST_METHOD');
        $target = self::requiredString($server, 'REQUEST_URI');

        $headers = new Headers();

        foreach ($server as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $name = self::headerName($key);

            if ($name === null) {
                continue;
            }

            if (!is_string($value)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Server header field "%s" must contain a string.',
                        $key,
                    ),
                );
            }

            $updated = $headers->with($name, $value);

            if (
                $headers->has($name)
                && $headers->get($name) !== $updated->get($name)
            ) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Conflicting server mappings for header "%s".',
                        $name,
                    ),
                );
            }

            $headers = $updated;
        }

        return new Request(
            method: $method,
            target: $target,
            headers: $headers,
            body: $body,
        );
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private static function requiredString(array $server, string $key): string
    {
        $value = $server[$key] ?? null;

        if (!is_string($value)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Server field "%s" must be present and contain a string.',
                    $key,
                ),
            );
        }

        return $value;
    }

    private static function headerName(string $key): ?string
    {
        if ($key === 'CONTENT_TYPE') {
            return 'content-type';
        }

        if ($key === 'CONTENT_LENGTH') {
            return 'content-length';
        }

        if (!str_starts_with($key, 'HTTP_')) {
            return null;
        }

        $name = strtolower(str_replace('_', '-', substr($key, 5)));

        return $name === 'proxy' ? null : $name;
    }
}
