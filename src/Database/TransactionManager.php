<?php

declare(strict_types=1);

namespace Flint\Database;

use Closure;
use Flint\Database\Exception\TransactionRollbackException;
use LogicException;
use PDO;
use RuntimeException;
use Throwable;

final class TransactionManager
{
    private bool $running = false;

    private bool $usable = true;

    public function __construct(
        private readonly PDO $connection,
    ) {}

    /**
     * @template TResult
     *
     * @param Closure(PDO): TResult $operation
     *
     * @return TResult
     */
    public function run(Closure $operation): mixed
    {
        if (!$this->usable) {
            throw new LogicException(
                'This transaction manager is unusable. Create a new connection.',
            );
        }

        if ($this->running || $this->connection->inTransaction()) {
            throw new LogicException(
                'Nested or externally owned transactions are not supported.',
            );
        }

        if (!$this->connection->beginTransaction()) {
            throw new RuntimeException('Could not begin the transaction.');
        }

        $this->running = true;

        try {
            $result = $operation($this->connection);

            if (!$this->connection->inTransaction()) {
                $this->usable = false;

                throw new LogicException(
                    'The callback ended the managed transaction unexpectedly.',
                );
            }

            try {
                if (!$this->connection->commit()) {
                    throw new RuntimeException('Could not commit the transaction.');
                }
            } catch (Throwable $exception) {
                // A failed commit can have an uncertain outcome.
                $this->usable = false;

                throw $exception;
            }

            return $result;
        } catch (Throwable $operationFailure) {
            try {
                if ($this->connection->inTransaction()) {
                    if (!$this->connection->rollBack()) {
                        throw new RuntimeException(
                            'Could not roll back the transaction.',
                        );
                    }
                } else {
                    // The callback or server ended the transaction.
                    $this->usable = false;
                }
            } catch (Throwable $rollbackFailure) {
                $this->usable = false;

                throw new TransactionRollbackException(
                    $operationFailure,
                    $rollbackFailure,
                );
            }

            throw $operationFailure;
        } finally {
            $this->running = false;
        }
    }
}
