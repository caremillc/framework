<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Database;

use Careminate\Database\Connection;
use Careminate\Database\ConnectionDefinition;
use Careminate\Database\ConnectionManager;
use Careminate\Database\MySql\MySqlConnection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConnectionManagerTest extends TestCase
{
    public function testManagerIsLazyAndCachesConnection(): void
    {
        $factoryCalls = 0;

        $manager = new ConnectionManager(
            'primary',
            [
                'primary' => new ConnectionDefinition(
                    'test',
                    [],
                ),
            ],
            [
                'test' => static function (
                    ConnectionDefinition $definition,
                ) use (&$factoryCalls): Connection {
                    ++$factoryCalls;

                    return self::fakeConnection();
                },
            ],
        );

        self::assertSame(0, $factoryCalls);

        $one = $manager->connection();

        self::assertSame(1, $factoryCalls);
        self::assertFalse($one->isConnected());
        self::assertSame(
            $one,
            $manager->connection('primary'),
        );
        self::assertSame(1, $factoryCalls);
    }

    public function testNamedConnectionsAreIndependent(): void
    {
        $manager = new ConnectionManager(
            'primary',
            [
                'primary' => new ConnectionDefinition('test', []),
                'replica' => new ConnectionDefinition('test', []),
            ],
            [
                'test' => static fn (
                    ConnectionDefinition $definition,
                ): Connection => self::fakeConnection(),
            ],
        );

        self::assertNotSame(
            $manager->connection(),
            $manager->connection('replica'),
        );

        self::assertSame(
            $manager->connection('primary'),
            $manager->connection(),
        );
    }

    public function testDisconnectAllClosesHandlesAndClearsCache(): void
    {
        $manager = new ConnectionManager(
            'primary',
            [
                'primary' => new ConnectionDefinition('test', []),
            ],
            [
                'test' => static fn (
                    ConnectionDefinition $definition,
                ): Connection => self::fakeConnection(),
            ],
        );

        $first = $manager->connection();
        $first->connect();

        self::assertTrue($first->isConnected());

        $manager->disconnectAll();

        self::assertFalse($first->isConnected());
        self::assertNotSame(
            $first,
            $manager->connection(),
        );
        self::assertFalse(
            $manager->connection()->isConnected(),
        );
    }

    public function testUnknownNameDoesNotEchoInput(): void
    {
        $manager = new ConnectionManager(
            'primary',
            [
                'primary' => new ConnectionDefinition('test', []),
            ],
            [
                'test' => static fn (
                    ConnectionDefinition $definition,
                ): Connection => self::fakeConnection(),
            ],
        );

        try {
            $manager->connection("PRIVATE\e[31m");

            self::fail('Expected an exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Unknown database connection.',
                $exception->getMessage(),
            );
        }
    }

    public function testUnknownDriverFailsWithoutOpeningConnection(): void
    {
        $manager = new ConnectionManager(
            'primary',
            [
                'primary' => new ConnectionDefinition(
                    'unimplemented',
                    [],
                ),
            ],
            [],
        );

        $this->expectException(
            InvalidArgumentException::class,
        );

        $this->expectExceptionMessage(
            'Unsupported database driver.',
        );

        $manager->connection();
    }

    public function testDefaultNameMustBeConfigured(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new ConnectionManager(
            'missing',
            [
                'primary' => new ConnectionDefinition(
                    'mysql',
                    [],
                ),
            ],
            [],
        );
    }

    public function testDefinitionRejectsBadDriverName(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new ConnectionDefinition(
            'mysql;password=secret',
            [],
        );
    }

    public function testDefinitionRejectsBadOptionKey(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new ConnectionDefinition(
            'mysql',
            [
                'host;dbname=secret' => '127.0.0.1',
            ],
        );
    }

    public function testDefinitionEnforcesOptionTypes(): void
    {
        $definition = new ConnectionDefinition(
            'mysql',
            [
                'host' => 'localhost',
                'port' => 3306,
            ],
        );

        self::assertSame(
            'localhost',
            $definition->string('host'),
        );

        self::assertSame(
            3306,
            $definition->integer('port'),
        );

        self::assertSame(
            ['host', 'port'],
            $definition->optionNames(),
        );
    }

    public function testMissingOptionProducesSafeError(): void
    {
        $definition = new ConnectionDefinition('mysql', []);

        $this->expectException(
            InvalidArgumentException::class,
        );

        $this->expectExceptionMessage(
            'Invalid database connection configuration.',
        );

        $definition->string('password');
    }

    public function testMySqlConfigurationDoesNotConnect(): void
    {
        $connection = MySqlConnection::fromDefinition(
            self::mysqlDefinition(),
        );

        self::assertSame('mysql', $connection->driver());
        self::assertFalse($connection->isConnected());
    }

    public function testHostDsnInjectionIsRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        MySqlConnection::fromDefinition(
            self::mysqlDefinition(
                host: 'localhost;dbname=other',
            ),
        );
    }

    public function testDatabaseDsnInjectionIsRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        MySqlConnection::fromDefinition(
            self::mysqlDefinition(
                database: 'test;charset=latin1',
            ),
        );
    }

    public function testInvalidPortIsRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        MySqlConnection::fromDefinition(
            self::mysqlDefinition(port: 65536),
        );
    }

    public function testInvalidTimeoutIsRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        MySqlConnection::fromDefinition(
            self::mysqlDefinition(timeout: 0),
        );
    }

    public function testUnknownDriverOptionIsRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        MySqlConnection::fromDefinition(
            new ConnectionDefinition(
                'mysql',
                [
                    'host' => 'localhost',
                    'database' => 'careminate',
                    'username' => 'careminate',
                    'ssl_disabled' => 'true',
                ],
            ),
        );
    }

    private static function mysqlDefinition(
        string $host = '127.0.0.1',
        string $database = 'careminate',
        int $port = 3306,
        int $timeout = 3,
    ): ConnectionDefinition {
        return new ConnectionDefinition(
            'mysql',
            [
                'host' => $host,
                'database' => $database,
                'username' => 'careminate',
                'password' => 'SENSITIVE_TEST_PASSWORD',
                'port' => $port,
                'connect_timeout' => $timeout,
            ],
        );
    }

    private static function fakeConnection(): Connection
    {
        return new class () implements Connection {
            private bool $connected = false;

            public function driver(): string
            {
                return 'test';
            }

            public function connect(): void
            {
                $this->connected = true;
            }

            public function isConnected(): bool
            {
                return $this->connected;
            }

            public function disconnect(): void
            {
                $this->connected = false;
            }
        };
    }
}
