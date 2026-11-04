<?php

declare(strict_types=1);

namespace Careminate\Tests\Unit\Database;

use Careminate\Database\ConnectionDefinition;
use Careminate\Database\MySql\MySqlConnection;
use Careminate\Database\SqlBindings;
use Careminate\Database\SqlResult;
use Careminate\Database\TransactionException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlContractsTest extends TestCase
{
    public function testPositionalParametersAreNumberedFromOne(): void
    {
        self::assertSame(
            [
                ['placeholder' => 1, 'value' => 42],
                ['placeholder' => 2, 'value' => null],
                ['placeholder' => 3, 'value' => true],
                ['placeholder' => 4, 'value' => 'text'],
            ],
            SqlBindings::normalize([42, null, true, 'text']),
        );
    }

    public function testNamedParametersGetColonPrefix(): void
    {
        self::assertSame(
            [
                ['placeholder' => ':id', 'value' => 7],
                ['placeholder' => ':_token', 'value' => 'abc'],
            ],
            SqlBindings::normalize([
                'id' => 7,
                '_token' => 'abc',
            ]),
        );
    }

    public function testEmptyParametersAreAllowed(): void
    {
        self::assertSame(
            [],
            SqlBindings::normalize([]),
        );
    }

    public function testUntrustedValueIsPreservedAsAValue(): void
    {
        $untrusted = "' OR 1=1 -- PRIVATE";

        self::assertSame(
            [
                [
                    'placeholder' => ':input',
                    'value' => $untrusted,
                ],
            ],
            SqlBindings::normalize([
                'input' => $untrusted,
            ]),
        );
    }

    /**
     * @return iterable<string, array{array<int|string, mixed>}>
     */
    public static function invalidBindings(): iterable
    {
        yield 'sparse positional indexes' => [
            [0 => 'a', 2 => 'b'],
        ];

        yield 'mixed named and numeric' => [
            ['name' => 'a', 0 => 'b'],
        ];

        yield 'colon-prefixed name' => [
            [':name' => 'a'],
        ];

        yield 'unsafe identifier' => [
            ['name;DROP TABLE x' => 'a'],
        ];

        yield 'floating point value' => [
            ['value' => 1.5],
        ];

        yield 'array value' => [
            ['value' => ['nested']],
        ];

        yield 'object value' => [
            ['value' => new \stdClass()],
        ];
    }

    /**
     * @param array<int|string, mixed> $parameters
     */
    #[DataProvider('invalidBindings')]
    public function testInvalidBindingsAreRejectedWithoutEchoingInputs(
        array $parameters,
    ): void {
        $this->expectException(
            InvalidArgumentException::class,
        );

        $this->expectExceptionMessage(
            'Invalid SQL parameter',
        );

        SqlBindings::normalize($parameters);
    }

    public function testResultReturnsFirstRowAndAllRows(): void
    {
        $rows = [
            ['id' => 1],
            ['id' => 2],
        ];

        $result = new SqlResult($rows, 0);

        self::assertSame(
            ['id' => 1],
            $result->first(),
        );

        self::assertSame(
            $rows,
            $result->rows(),
        );

        self::assertSame(
            2,
            $result->returnedRowCount(),
        );
    }

    public function testWriteResultCanContainNoRows(): void
    {
        $result = new SqlResult([], 3);

        self::assertNull($result->first());
        self::assertSame([], $result->rows());
        self::assertSame(0, $result->returnedRowCount());
        self::assertSame(3, $result->affectedRows);
    }

    public function testResultRejectsNegativeAffectedRowCount(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new SqlResult([], -1);
    }

    public function testAdapterDoesNotConnectDuringConstruction(): void
    {
        $connection = self::connection();

        self::assertSame(
            'mysql',
            $connection->driver(),
        );

        self::assertFalse($connection->isConnected());
        self::assertFalse($connection->inTransaction());
    }

    public function testCommitWithoutTransactionIsRejectedWithoutConnecting(): void
    {
        $connection = self::connection();

        $this->expectException(
            TransactionException::class,
        );

        $this->expectExceptionMessage(
            'No active database transaction.',
        );

        $connection->commit();
    }

    public function testRollbackWithoutTransactionIsRejectedWithoutConnecting(): void
    {
        $connection = self::connection();

        $this->expectException(
            TransactionException::class,
        );

        $this->expectExceptionMessage(
            'No active database transaction.',
        );

        $connection->rollBack();
    }

    private static function connection(): MySqlConnection
    {
        return MySqlConnection::fromDefinition(
            new ConnectionDefinition(
                'mysql',
                [
                    'host' => '127.0.0.1',
                    'port' => 3306,
                    'database' => 'careminate_test',
                    'username' => 'test',
                    'password' => 'DO_NOT_PRINT_SECRET',
                ],
            ),
        );
    }
}
