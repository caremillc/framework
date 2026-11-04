<?php

declare(strict_types=1);

namespace Careminate\Tests\Integration\Database;

use Careminate\Database\ConnectionDefinition;
use Careminate\Database\ConnectionException;
use Careminate\Database\MySql\MySqlConnection;
use PHPUnit\Framework\TestCase;

/**
 * Requires a real MySQL server and explicit test credentials.
 */
final class MySqlConnectionIntegrationTest extends TestCase
{
    public function testRealMySqlConnectionLifecycle(): void
    {
        $connection = MySqlConnection::fromDefinition(
            self::configuration(),
        );

        self::assertFalse($connection->isConnected());

        try {
            $connection->connect();

            self::assertTrue($connection->isConnected());

            $connection->connect();

            // Repeated connection attempts are idempotent.
            self::assertTrue($connection->isConnected());
        } finally {
            $connection->disconnect();
        }

        self::assertFalse($connection->isConnected());

        $connection->connect();

        self::assertTrue($connection->isConnected());

        $connection->disconnect();
    }

    public function testRealMySqlFailureDoesNotExposeCredentials(): void
    {
        $configured = self::configuration();

        // Use valid credentials against a database that should
        // not exist. No schema changes are made.
        $connection = MySqlConnection::fromDefinition(
            new ConnectionDefinition(
                'mysql',
                [
                    'host' => $configured->string('host'),
                    'port' => $configured->integer('port'),
                    'database' => 'careminate_missing_'
                        . bin2hex(random_bytes(8)),
                    'username' => $configured->string('username'),
                    'password' => $configured->string('password'),
                    'connect_timeout' => 3,
                ],
            ),
        );

        try {
            $connection->connect();

            self::fail(
                'Expected the missing MySQL database to fail.',
            );
        } catch (ConnectionException $exception) {
            self::assertSame(
                'Database connection failed.',
                $exception->getMessage(),
            );

            self::assertStringNotContainsString(
                $configured->string('password'),
                $exception->getMessage(),
            );

            self::assertFalse($connection->isConnected());
        }
    }

    private static function configuration(): ConnectionDefinition
    {
        if (! extension_loaded('pdo_mysql')) {
            self::markTestSkipped(
                'Real MySQL integration requires pdo_mysql.',
            );
        }

        $host = getenv('CAREMINATE_TEST_MYSQL_HOST');
        $database = getenv('CAREMINATE_TEST_MYSQL_DATABASE');
        $username = getenv('CAREMINATE_TEST_MYSQL_USERNAME');
        $password = getenv('CAREMINATE_TEST_MYSQL_PASSWORD');
        $port = getenv('CAREMINATE_TEST_MYSQL_PORT');

        if (
            ! is_string($host)
            || $host === ''
            || ! is_string($database)
            || $database === ''
            || ! is_string($username)
            || $username === ''
            || ! is_string($password)
            || $password === ''
        ) {
            self::markTestSkipped(
                'Real MySQL integration credentials not configured.',
            );
        }

        $numericPort = is_string($port)
            && ctype_digit($port)
                ? (int) $port
                : 3306;

        return new ConnectionDefinition(
            'mysql',
            [
                'host' => $host,
                'port' => $numericPort,
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'connect_timeout' => 3,
            ],
        );
    }
}
