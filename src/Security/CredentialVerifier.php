<?php

declare(strict_types=1);

namespace Careminate\Security;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class CredentialVerifier
{
    public function __construct(
        private PasswordHasher $hasher,
        private string $dummyHash,
    ) {
        if (
            password_get_info($dummyHash)['algoName']
            !== 'bcrypt'
        ) {
            throw new InvalidArgumentException(
                'Dummy credential hash must use bcrypt.',
            );
        }
    }

    public function verify(
        #[SensitiveParameter]
        string $password,
        ?string $storedHash,
    ): bool {
        $hash = $storedHash ?? $this->dummyHash;

        $valid = $this->hasher->verify(
            $password,
            $hash,
        );

        return $storedHash !== null && $valid;
    }
}
