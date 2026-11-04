<?php

declare(strict_types=1);

namespace Careminate\Database;

use Closure;

/** SQL execution and transaction contract, independent of PDO. */
interface SqlConnection extends Connection
{
    /**
     * SQL text must be trusted application SQL; bind all untrusted values.
     *
     * @param array<int|string, mixed> $parameters
     */
    public function execute(
        string $sql,
        array $parameters = [],
    ): SqlResult;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;

    public function inTransaction(): bool;

    /**
     * Run a unit of work atomically on one connection.
     * Nested transactions are rejected (no savepoints in 0.1).
     *
     * @template T
     * @param Closure(SqlConnection): T $operation
     * @return T
     */
    public function transactional(
        Closure $operation,
    ): mixed;

    /** Last generated identifier, if supported by the driver. */
    public function lastInsertId(): string;
}
