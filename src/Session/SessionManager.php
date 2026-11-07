<?php

declare(strict_types=1);

namespace Careminate\Session;

use Closure;
use InvalidArgumentException;
use RuntimeException;

final class SessionManager
{
    private const LAST_ACTIVITY = '__careminate_last_activity';
    private const OBSOLETE_AT = '__careminate_obsolete_at';

    private readonly Closure $clock;

    public function __construct(
        private readonly SessionCookieConfig $cookieConfig,
        private readonly int $idleTimeout = 1800,
        ?Closure $clock = null,
    ) {
        if ($idleTimeout < 1) {
            throw new InvalidArgumentException(
                'Session inactivity timeout must be positive.'
            );
        }

        $this->clock = $clock ?? static fn (): int => time();
    }

    public function start(): void
    {
        if ($this->isActive()) {
            throw new RuntimeException(
                'A PHP session is already active.'
            );
        }

        if (headers_sent($file, $line)) {
            throw new RuntimeException(
                "Cannot start session: headers sent at {$file}:{$line}."
            );
        }

        foreach (
            [
                'session.use_strict_mode' => '1',
                'session.use_only_cookies' => '1',
                'session.use_trans_sid' => '0',
            ] as $name => $value
        ) {
            if (ini_set($name, $value) === false) {
                throw new RuntimeException(
                    "Unable to configure {$name}."
                );
            }
        }

        if (! session_set_cookie_params(
            $this->cookieConfig->toPhpOptions()
        )) {
            throw new RuntimeException(
                'Unable to configure session cookies.'
            );
        }

        if (! session_start()) {
            throw new RuntimeException(
                'Unable to start PHP session.'
            );
        }

        $now = $this->now();
        $lastActivity = $_SESSION[self::LAST_ACTIVITY] ?? null;

        $obsolete = array_key_exists(
            self::OBSOLETE_AT,
            $_SESSION
        );

        $expired = $lastActivity !== null
            && (
                ! is_int($lastActivity)
                || $lastActivity > $now
                || ($now - $lastActivity) >= $this->idleTimeout
            );

        if ($obsolete || $expired) {
            // Do not allow access to old session data.
            // Rotate to a fresh, unauthenticated session.
            $this->rotate(true);
        }

        $_SESSION[self::LAST_ACTIVITY] = $now;
    }

    public function isActive(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public function get(
        string $key,
        mixed $default = null,
    ): mixed {
        $this->requireActive();
        $this->validateKey($key);

        return $_SESSION[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        $this->requireActive();
        $this->validateKey($key);

        return array_key_exists($key, $_SESSION);
    }

    public function set(string $key, mixed $value): void
    {
        $this->requireActive();
        $this->validateKey($key);

        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->requireActive();
        $this->validateKey($key);

        unset($_SESSION[$key]);
    }

    /**
     * Call before storing authenticated identity.
     */
    public function rotateForAuthentication(): void
    {
        $this->requireActive();

        $this->rotate(true);

        $_SESSION[self::LAST_ACTIVITY] = $this->now();
    }

    /**
     * Invalidate the current authenticated session.
     *
     * The old session remains marked obsolete for replay detection.
     * The new session contains no authenticated state.
     */
    public function logout(): void
    {
        $this->requireActive();

        // Mark the old session obsolete and move to a clean ID.
        $this->rotate(true);

        $_SESSION[self::LAST_ACTIVITY] = $this->now();

        $params = session_get_cookie_params();

        $expired = setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 3600,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]
        );

        if (! $expired) {
            $this->close();

            throw new RuntimeException(
                'Unable to expire the session cookie.'
            );
        }

        $this->close();
    }


    public function close(): void
    {
        $this->requireActive();

        if (! session_write_close()) {
            throw new RuntimeException(
                'Unable to close PHP session.'
            );
        }
    }

    private function rotate(bool $clearData): void
    {
        $this->requireActive();

        $now = $this->now();

        // The old session record is marked obsolete.
        $_SESSION[self::OBSOLETE_AT] = $now;

        if (! session_regenerate_id(false)) {
            throw new RuntimeException(
                'Unable to regenerate PHP session ID.'
            );
        }

        if ($clearData) {
            $_SESSION = [];
        } else {
            unset($_SESSION[self::OBSOLETE_AT]);
        }
    }

    private function requireActive(): void
    {
        if (! $this->isActive()) {
            throw new RuntimeException(
                'PHP session is not active.'
            );
        }
    }

    private function validateKey(string $key): void
    {
        if (
            $key === ''
            || str_starts_with($key, '__careminate_')
        ) {
            throw new InvalidArgumentException(
                'Invalid or reserved session key.'
            );
        }
    }

    private function now(): int
    {
        return ($this->clock)();
    }
}
