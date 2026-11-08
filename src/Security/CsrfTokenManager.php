<?php

declare(strict_types=1);

namespace Careminate\Security;

use Careminate\Session\SessionManager;

final readonly class CsrfTokenManager
{
    private const string SESSION_KEY = 'csrf_token';

    public function __construct(
        private SessionManager $sessions,
    ) {
    }

    /**
     * Return the current token or generate a new one.
     *
     * Tokens contain 256 bits of randomness.
     */
    public function token(): string
    {
        $current = $this->sessions->get(
            self::SESSION_KEY
        );

        if ($this->isValidFormat($current)) {
            return $current;
        }

        return $this->regenerate();
    }

    /**
     * Verify a submitted token without modifying the session.
     */
    public function verify(mixed $submitted): bool
    {
        if (! $this->isValidFormat($submitted)) {
            return false;
        }

        $expected = $this->sessions->get(
            self::SESSION_KEY
        );

        if (! $this->isValidFormat($expected)) {
            return false;
        }

        return hash_equals($expected, $submitted);
    }

    /**
     * Replace the existing CSRF token.
     *
     * Use after security-sensitive authentication transitions.
     */
    public function regenerate(): string
    {
        $token = bin2hex(random_bytes(32));

        $this->sessions->set(
            self::SESSION_KEY,
            $token
        );

        return $token;
    }

    private function isValidFormat(mixed $token): bool
    {
        return is_string($token)
            && preg_match(
                '/\A[a-f0-9]{64}\z/D',
                $token
            ) === 1;
    }
}
