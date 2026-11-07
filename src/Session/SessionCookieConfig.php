<?php

declare(strict_types=1);

namespace Careminate\Session;

use InvalidArgumentException;

final readonly class SessionCookieConfig
{
    public function __construct(
        public int $lifetime = 0,
        public string $path = '/',
        public string $domain = '',
        public bool $secure = true,
        public bool $httpOnly = true,
        public string $sameSite = 'Lax',
    ) {
        if ($lifetime < 0) {
            throw new InvalidArgumentException(
                'Session cookie lifetime must be non-negative.'
            );
        }

        if (
            $path === ''
            || $path[0] !== '/'
            || preg_match('/[\x00-\x1F\x7F;]/', $path) === 1
        ) {
            throw new InvalidArgumentException(
                'Session cookie path must be an absolute path without control characters or semicolons.'
            );
        }

        if (
            preg_match('/[\x00-\x20\x7F;,\/\\\\]/', $domain) === 1
        ) {
            throw new InvalidArgumentException(
                'Invalid session cookie domain.'
            );
        }

        if (! in_array($sameSite, ['Lax', 'Strict'], true)) {
            throw new InvalidArgumentException(
                'Session SameSite must be Lax or Strict.'
            );
        }

        if (! $httpOnly) {
            throw new InvalidArgumentException(
                'Session cookies must use HttpOnly.'
            );
        }
    }

    /**
     * @return array{
     *     lifetime: int,
     *     path: string,
     *     domain: string,
     *     secure: bool,
     *     httponly: bool,
     *     samesite: string
     * }
     */
    public function toPhpOptions(): array
    {
        return [
            'lifetime' => $this->lifetime,
            'path' => $this->path,
            'domain' => $this->domain,
            'secure' => $this->secure,
            'httponly' => $this->httpOnly,
            'samesite' => $this->sameSite,
        ];
    }
}
