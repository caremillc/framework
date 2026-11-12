<?php

declare(strict_types=1);

namespace Careminate\Security;

use Careminate\Session\SessionManager;
use Closure;
use LogicException;
use SensitiveParameter;
use UnexpectedValueException;

final readonly class SessionLoginService
{
    private const string USER_ID_KEY = 'auth_user_id';

    /**
     * @param Closure(string): ?array{
     *     id: string,
     *     password_hash: string
     * } $findCredentials
     */
    public function __construct(
        private SessionManager $sessions,
        private CredentialVerifier $verifier,
        private Closure $findCredentials,
    ) {
    }

    public function login(
        string $email,
        #[SensitiveParameter]
        string $password,
    ): bool {
        $this->assertActiveSession();

        if ($this->currentUserId() !== null) {
            throw new LogicException(
                'An authenticated session already exists.',
            );
        }

        $credentials = null;

        if ($email !== '') {
            $credentials = ($this->findCredentials)($email);
        }

        $storedHash = $credentials['password_hash'] ?? null;

        if (! $this->verifier->verify(
            $password,
            $storedHash,
        )) {
            return false;
        }

        if (
            $credentials === null
            || ($credentials['id'] ?? '') === ''
        ) {
            throw new UnexpectedValueException(
                'Credential lookup returned an invalid user.',
            );
        }

        // Prevent session fixation after authentication.
        $this->sessions->rotateForAuthentication();

        $this->sessions->set(
            self::USER_ID_KEY,
            $credentials['id'],
        );

        return true;
    }

    public function currentUserId(): ?string
    {
        $this->assertActiveSession();

        $value = $this->sessions->get(
            self::USER_ID_KEY,
        );

        if ($value === null) {
            return null;
        }

        if (
            ! is_string($value)
            || $value === ''
        ) {
            throw new UnexpectedValueException(
                'Invalid authenticated session identity.',
            );
        }

        return $value;
    }

    public function logout(): void
    {
        $this->assertActiveSession();

        $this->sessions->logout();
    }

    private function assertActiveSession(): void
    {
        if (! $this->sessions->isActive()) {
            throw new LogicException(
                'An active session is required.',
            );
        }
    }
}
