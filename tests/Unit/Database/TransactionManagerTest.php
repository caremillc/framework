<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Database;

use Flint\Database\Exception\TransactionRollbackException;
use Flint\Database\TransactionManager;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TransactionManagerTest extends TestCase
{
    public function testRollbackFailurePreservesBothExceptions(): void
    {
        $operationFailure = new RuntimeException('Operation failed.');
        $rollbackFailure = new RuntimeException('Rollback failed.');

        $connection = $this->createMock(PDO::class);

        $connection->expects(self::exactly(2))
            ->method('inTransaction')
            ->willReturnOnConsecutiveCalls(false, true);

        $connection->expects(self::once())
            ->method('beginTransaction')
            ->willReturn(true);

        $connection->expects(self::never())
            ->method('commit');

        $connection->expects(self::once())
            ->method('rollBack')
            ->willThrowException($rollbackFailure);

        $manager = new TransactionManager($connection);
        $caught = null;

        try {
            $manager->run(
                static function (PDO $pdo) use ($operationFailure): void {
                    throw $operationFailure;
                },
            );
        } catch (TransactionRollbackException $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(TransactionRollbackException::class, $caught);
        self::assertSame($operationFailure, $caught->operationFailure);
        self::assertSame($operationFailure, $caught->getPrevious());
        self::assertSame($rollbackFailure, $caught->rollbackFailure);

        $this->assertUnusable($manager);
    }

    public function testFailedCommitIsNotRetriedEvenWhenRollbackSucceeds(): void
    {
        $commitFailure = new RuntimeException('Commit outcome uncertain.');

        $connection = $this->createMock(PDO::class);

        $connection->expects(self::exactly(3))
            ->method('inTransaction')
            ->willReturnOnConsecutiveCalls(false, true, true);

        $connection->expects(self::once())
            ->method('beginTransaction')
            ->willReturn(true);

        $connection->expects(self::once())
            ->method('commit')
            ->willThrowException($commitFailure);

        $connection->expects(self::once())
            ->method('rollBack')
            ->willReturn(true);

        $manager = new TransactionManager($connection);
        $calls = 0;
        $caught = null;

        try {
            $manager->run(
                static function (PDO $pdo) use (&$calls): string {
                    ++$calls;

                    return 'result';
                },
            );
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        self::assertSame($commitFailure, $caught);
        self::assertSame(1, $calls);

        $this->assertUnusable($manager);
    }

    public function testUnexpectedTransactionEndDisablesTheManager(): void
    {
        $connection = $this->createMock(PDO::class);

        $connection->expects(self::exactly(3))
            ->method('inTransaction')
            ->willReturnOnConsecutiveCalls(false, false, false);

        $connection->expects(self::once())
            ->method('beginTransaction')
            ->willReturn(true);

        $connection->expects(self::never())
            ->method('commit');

        $connection->expects(self::never())
            ->method('rollBack');

        $manager = new TransactionManager($connection);
        $caught = null;

        try {
            $manager->run(
                static fn (PDO $pdo): string => 'result',
            );
        } catch (LogicException $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(LogicException::class, $caught);
        self::assertSame(
            'The callback ended the managed transaction unexpectedly.',
            $caught->getMessage(),
        );

        $this->assertUnusable($manager);
    }

    public function testFailedBeginDoesNotExecuteWorkOrAttemptRollback(): void
    {
        $connection = $this->createMock(PDO::class);

        $connection->expects(self::once())
            ->method('inTransaction')
            ->willReturn(false);

        $connection->expects(self::once())
            ->method('beginTransaction')
            ->willReturn(false);

        $connection->expects(self::never())
            ->method('commit');

        $connection->expects(self::never())
            ->method('rollBack');

        $manager = new TransactionManager($connection);
        $called = false;
        $caught = null;

        try {
            $manager->run(
                static function (PDO $pdo) use (&$called): void {
                    $called = true;
                },
            );
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        self::assertFalse($called);
        self::assertInstanceOf(RuntimeException::class, $caught);
        self::assertSame(
            'Could not begin the transaction.',
            $caught->getMessage(),
        );
    }

    public function testFalseCommitResultIsTreatedAsFailure(): void
    {
        $connection = $this->createMock(PDO::class);

        $connection->expects(self::exactly(3))
            ->method('inTransaction')
            ->willReturnOnConsecutiveCalls(false, true, true);

        $connection->expects(self::once())
            ->method('beginTransaction')
            ->willReturn(true);

        $connection->expects(self::once())
            ->method('commit')
            ->willReturn(false);

        $connection->expects(self::once())
            ->method('rollBack')
            ->willReturn(true);

        $manager = new TransactionManager($connection);
        $caught = null;

        try {
            $manager->run(
                static fn (PDO $pdo): string => 'result',
            );
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(RuntimeException::class, $caught);
        self::assertSame(
            'Could not commit the transaction.',
            $caught->getMessage(),
        );

        $this->assertUnusable($manager);
    }

    private function assertUnusable(TransactionManager $manager): void
    {
        $called = false;
        $caught = null;

        try {
            $manager->run(
                static function (PDO $pdo) use (&$called): void {
                    $called = true;
                },
            );
        } catch (LogicException $exception) {
            $caught = $exception;
        }

        self::assertFalse($called);
        self::assertInstanceOf(LogicException::class, $caught);
        self::assertSame(
            'This transaction manager is unusable. Create a new connection.',
            $caught->getMessage(),
        );
    }
}
