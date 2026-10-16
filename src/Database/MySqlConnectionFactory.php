<?php

declare(strict_types=1);

namespace Flint\Database;

use Flint\Database\Exception\DatabaseConnectionException;
use InvalidArgumentException;
use PDO;
use PDOException;
use SensitiveParameter;

final class MySqlConnectionFactory
{
    public function connect(
        string $database,
        string $username,
        #[SensitiveParameter]
        string $password,
        int $port = 3306,
    ): PDO {
        // This first adapter deliberately supports simple database names.
        if (
            preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $database) !== 1
        ) {
            throw new InvalidArgumentException(
                'Database names must contain 1 to 64 ASCII letters, digits, or underscores.',
            );
        }

        if (
            trim($username) === ''
            || str_contains($username, "\0")
        ) {
            throw new InvalidArgumentException(
                'Database usernames must be non-blank and contain no null bytes.',
            );
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException(
                'Database ports must be between 1 and 65535.',
            );
        }

        if (!extension_loaded('pdo_mysql')) {
            throw new DatabaseConnectionException(
                'MySQL support requires the pdo_mysql PHP extension.',
            );
        }

        $dsn = sprintf(
            'mysql:host=127.0.0.1;port=%d;dbname=%s;charset=utf8mb4',
            $port,
            $database,
        );

        try {
            return new PDO(
                $dsn,
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => false,
                    PDO::ATTR_TIMEOUT => 3,
                ],
            );
        } catch (PDOException) {
            // Do not attach a driver exception containing unrestricted details.
            throw new DatabaseConnectionException(
                'MySQL connection failed. Check the service, database, credentials, and permissions.',
            );
        }
    }
}
