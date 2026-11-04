<?php

declare(strict_types=1);

namespace Careminate\Tests\Integration\Database;

use Careminate\Database\ConnectionDefinition;
use Careminate\Database\MySql\MySqlConnection;
use Careminate\Database\QueryException;
use Careminate\Database\SqlConnection;
use Careminate\Database\TransactionException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MySqlSqlExecutionIntegrationTest extends TestCase
{
    private MySqlConnection $connection;

    protected function setUp(): void
    {
        $definition = self::testDefinition();

        $this->connection = MySqlConnection::fromDefinition(
            $definition,
        );

        $this->connection->connect();

        $this->connection->execute(
            'CREATE TEMPORARY TABLE careminate_day26_records ('
                . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
                . 'label VARCHAR(255) NOT NULL'
                . ') ENGINE=InnoDB',
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->disconnect();
        }
    }

    public function testNamedParametersPreserveInjectionLikeText(): void
    {
        $input = "' OR 1=1 -- PRIVATE";

        $result = $this->connection->execute(
            'SELECT :input AS echoed',
            ['input' => $input],
        );

        self::assertSame(
            $input,
            $result->first()['echoed'] ?? null,
        );

        self::assertSame(
            1,
            $result->returnedRowCount(),
        );
    }

    public function testPositionalParametersAreBoundSeparately(): void
    {
        $result = $this->connection->execute(
            'SELECT ? AS first_value, ? AS second_value',
            ['alpha', 42],
        );

        $first = $result->first();

        self::assertNotNull($first);

        self::assertSame(
            'alpha',
            $first['first_value'] ?? null,
        );

        $second = $first['second_value'] ?? null;

        if (! is_int($second) && ! is_string($second)) {
            throw new RuntimeException(
                'Invalid positional parameter result.',
            );
        }

        self::assertSame(
            '42',
            (string) $second,
        );
    }

    public function testInsertReportsAffectedRowsAndGeneratedId(): void
    {
        $insert = $this->connection->execute(
            'INSERT INTO careminate_day26_records (label) '
                . 'VALUES (:label)',
            ['label' => 'created'],
        );

        self::assertSame(
            1,
            $insert->affectedRows,
        );

        self::assertSame(
            [],
            $insert->rows(),
        );

        self::assertGreaterThan(
            0,
            (int) $this->connection->lastInsertId(),
        );

        self::assertSame(
            1,
            $this->rowCount(),
        );
    }

    public function testManualCommitPersistsChanges(): void
    {
        $this->connection->beginTransaction();

        self::assertTrue(
            $this->connection->inTransaction(),
        );

        $this->insert('committed');

        $this->connection->commit();

        self::assertFalse(
            $this->connection->inTransaction(),
        );

        self::assertSame(
            1,
            $this->rowCount(),
        );
    }

    public function testManualRollbackRemovesChanges(): void
    {
        $this->connection->beginTransaction();

        $this->insert('rolled back');

        $this->connection->rollBack();

        self::assertFalse(
            $this->connection->inTransaction(),
        );

        self::assertSame(
            0,
            $this->rowCount(),
        );
    }

    public function testTransactionalCallbackCommitsAndReturnsValue(): void
    {
        $value = $this->connection->transactional(
            function (SqlConnection $connection): string {
                $connection->execute(
                    'INSERT INTO careminate_day26_records (label) '
                        . 'VALUES (?)',
                    ['committed by callback'],
                );

                return 'done';
            },
        );

        self::assertSame('done', $value);
        self::assertSame(1, $this->rowCount());

        self::assertFalse(
            $this->connection->inTransaction(),
        );
    }

    public function testTransactionalCallbackRollsBackAndRethrows(): void
    {
        $caught = null;

        try {
            $this->connection->transactional(
                function (SqlConnection $connection): void {
                    $connection->execute(
                        'INSERT INTO careminate_day26_records (label) '
                            . 'VALUES (?)',
                        ['must not persist'],
                    );

                    throw new RuntimeException(
                        'Application failure.',
                    );
                },
            );
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(
            RuntimeException::class,
            $caught,
        );

        self::assertSame(
            'Application failure.',
            $caught->getMessage(),
        );

        self::assertSame(0, $this->rowCount());

        self::assertFalse(
            $this->connection->inTransaction(),
        );
    }

    public function testNestedTransactionsAreRejectedAndOuterWorkIsRolledBack(): void
    {
        try {
            $this->connection->transactional(
                function (SqlConnection $connection): void {
                    $connection->execute(
                        'INSERT INTO careminate_day26_records (label) '
                            . 'VALUES (?)',
                        ['outer work'],
                    );

                    $connection->transactional(
                        static function (
                            SqlConnection $ignored,
                        ): void {
                        },
                    );
                },
            );

            self::fail(
                'Expected a nested transaction rejection.',
            );
        } catch (TransactionException $exception) {
            self::assertSame(
                'Nested database transactions are not supported.',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            0,
            $this->rowCount(),
        );

        self::assertFalse(
            $this->connection->inTransaction(),
        );
    }

    public function testCommitWithoutActiveTransactionIsRejected(): void
    {
        $this->expectException(
            TransactionException::class,
        );

        $this->expectExceptionMessage(
            'No active database transaction.',
        );

        $this->connection->commit();
    }

    public function testRollbackWithoutActiveTransactionIsRejected(): void
    {
        $this->expectException(
            TransactionException::class,
        );

        $this->expectExceptionMessage(
            'No active database transaction.',
        );

        $this->connection->rollBack();
    }

    public function testInvalidSqlFailsWithSanitizedException(): void
    {
        try {
            $this->connection->execute(
                'SELECT FROM PRIVATE_SQL_MARKER',
            );

            self::fail('Expected a query failure.');
        } catch (QueryException $exception) {
            self::assertSame(
                'Database query failed.',
                $exception->getMessage(),
            );

            self::assertNull(
                $exception->getPrevious(),
            );

            self::assertTrue(
                $this->connection->isConnected(),
            );
        }
    }

    public function testDisconnectClearsActiveTransactionAndConnection(): void
    {
        $this->connection->beginTransaction();

        $this->insert('unfinished');

        $this->connection->disconnect();

        self::assertFalse(
            $this->connection->isConnected(),
        );

        self::assertFalse(
            $this->connection->inTransaction(),
        );
    }

    private function insert(string $label): void
    {
        $this->connection->execute(
            'INSERT INTO careminate_day26_records (label) '
                . 'VALUES (:label)',
            ['label' => $label],
        );
    }

    private function rowCount(): int
    {
        $result = $this->connection->execute(
            'SELECT COUNT(*) AS total '
                . 'FROM careminate_day26_records',
        );

        $total = $result->first()['total'] ?? null;

        if (! is_int($total) && ! is_string($total)) {
            throw new RuntimeException(
                'Invalid COUNT result.',
            );
        }

        return (int) $total;
    }

    private static function testDefinition(): ConnectionDefinition
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

        return new ConnectionDefinition(
            'mysql',
            [
                'host' => $host,
                'port' => is_string($port) && ctype_digit($port)
                    ? (int) $port
                    : 3306,
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'connect_timeout' => 3,
            ],
        );
    }
}
