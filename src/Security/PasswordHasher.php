<?php

declare(strict_types=1);

namespace Careminate\Security;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class PasswordHasher
{
    private const int COST = 12;

    public function hash(
        #[SensitiveParameter]
        string $password,
    ): string {
        self::assertValidPassword($password);

        return password_hash(
            $password,
            PASSWORD_BCRYPT,
            ['cost' => self::COST],
        );
    }

    public function verify(
        #[SensitiveParameter]
        string $password,
        string $hash,
    ): bool {
        if (! self::isValidPassword($password)) {
            return false;
        }

        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash(
            $hash,
            PASSWORD_BCRYPT,
            ['cost' => self::COST],
        );
    }

    private static function assertValidPassword(
        string $password,
    ): void {
        if (! self::isValidPassword($password)) {
            throw new InvalidArgumentException(
                'Password must contain 1 to 72 bytes '
                . 'and must not contain a null byte.',
            );
        }
    }

    private static function isValidPassword(
        string $password,
    ): bool {
        $length = strlen($password);

        return $length >= 1
            && $length <= 72
            && ! str_contains($password, "\0");
    }
}
