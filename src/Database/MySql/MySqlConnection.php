<?php

declare (strict_types = 1);

namespace Careminate\Database\MySql;

use Careminate\Database\ConnectionDefinition;
use Careminate\Database\ConnectionException;
use Careminate\Database\QueryException;
use Careminate\Database\SqlBindings;
use Careminate\Database\SqlConnection;
use Careminate\Database\SqlResult;
use Careminate\Database\TransactionException;
use Closure;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/** PDO MySQL adapter; no database connection during construction. */
final class MySqlConnection implements SqlConnection
{
    private ?PDO $pdo = null;

    private function __construct(
        private readonly string $dsn,
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeout,
    ) {
    }

    public static function fromDefinition(
        ConnectionDefinition $definition,
    ): self {
        if ($definition->driver !== 'mysql') {
            throw new InvalidArgumentException(
                'Unsupported database driver.',
            );
        }

        foreach ($definition->optionNames() as $option) {
            if (
                ! in_array(
                    $option,
                    [
                        'host',
                        'port',
                        'database',
                        'username',
                        'password',
                        'connect_timeout',
                    ],
                    true,
                )
            ) {
                throw new InvalidArgumentException(
                    'Invalid database connection configuration.',
                );
            }
        }

        $host = $definition->string(
            'host',
            '127.0.0.1',
        );

        $port     = $definition->integer('port', 3306);
        $database = $definition->string('database');
        $username = $definition->string('username');
        $password = $definition->string('password', '');
        $timeout  = $definition->integer(
            'connect_timeout',
            3,
        );

        if (
            preg_match(
                '/\A[a-zA-Z0-9.\-_]+\z/D',
                $host,
            ) !== 1
            || preg_match(
                '/\A[a-zA-Z0-9_\-]+\z/D',
                $database,
            ) !== 1
            || $username === ''
            || $port < 1
            || $port > 65535
            || $timeout < 1
            || $timeout > 30
        ) {
            throw new InvalidArgumentException(
                'Invalid database connection configuration.',
            );
        }

        return new self(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $host,
                $port,
                $database,
            ),
            $username,
            $password,
            $timeout,
        );
    }

    public function driver(): string
    {
        return 'mysql';
    }

    // public function connect(): void
    // {
    //     if ($this->pdo !== null) {
    //         return;
    //     }

    //     try {
    //         $this->pdo = new PDO(
    //             $this->dsn,
    //             $this->username,
    //             $this->password,
    //             [
    //                 PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    //                 PDO::ATTR_EMULATE_PREPARES => false,
    //                 PDO::ATTR_PERSISTENT => false,
    //                 PDO::ATTR_TIMEOUT => $this->timeout,
    //                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    //             ],
    //         );
    //     } catch (PDOException) {
    //         throw new ConnectionException(
    //             'Database connection failed.',
    //         );
    //     }
    // }

    public function connect(): void
    {
        if ($this->pdo !== null) {
            return;
        }

        try {
            $pdo = new PDO(
                $this->dsn,
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_PERSISTENT         => false,
                    PDO::ATTR_TIMEOUT            => $this->timeout,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ],
            );

            // The reference schema stores UTC DATETIME values.
            // This does not require MySQL timezone tables.
            $pdo->exec("SET SESSION time_zone = '+00:00'");

            // Publish the handle only after session setup succeeds.
            $this->pdo = $pdo;
        } catch (PDOException) {
            throw new ConnectionException(
                'Database connection failed.',
            );
        }
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    public function disconnect(): void
    {
        $pdo       = $this->pdo;
        $this->pdo = null;

        if ($pdo === null) {
            return;
        }

        try {
            if (
                $pdo->inTransaction()
                && ! $pdo->rollBack()
            ) {
                throw new TransactionException(
                    'Database rollback failed.',
                );
            }
        } catch (PDOException) {
            // Handle is already detached from this adapter.
            throw new TransactionException(
                'Database rollback failed.',
            );
        }
    }

    /**
     * @param array<int|string, mixed> $parameters
     */
    public function execute(
        string $sql,
        array $parameters = [],
    ): SqlResult {
        if (
            trim($sql) === ''
            || str_contains($sql, "\0")
        ) {
            throw new InvalidArgumentException(
                'Invalid SQL statement.',
            );
        }

        // Reject invalid parameter shapes before connecting.
        $bindings = SqlBindings::normalize($parameters);

        $this->connect();
        $pdo = $this->requirePdo();

        try {
            $statement = $pdo->prepare($sql);

            if ($statement === false) {
                throw new QueryException(
                    'Database query failed.',
                );
            }

            foreach ($bindings as $binding) {
                $value = $binding['value'];

                $type = match (true) {
                    $value === null => PDO::PARAM_NULL,
                    is_bool($value) => PDO::PARAM_BOOL,
                    is_int($value)  => PDO::PARAM_INT,
                    default         => PDO::PARAM_STR,
                };

                if (
                    ! $statement->bindValue(
                        $binding['placeholder'],
                        $value,
                        $type,
                    )
                ) {
                    throw new QueryException(
                        'Database query failed.',
                    );
                }
            }

            if (! $statement->execute()) {
                throw new QueryException(
                    'Database query failed.',
                );
            }

            $rows = [];

            if ($statement->columnCount() > 0) {
                while (true) {
                    $row = $statement->fetch(
                        PDO::FETCH_ASSOC,
                    );

                    if ($row === false) {
                        break;
                    }

                    /** @var array<string, mixed> $row */
                    $rows[] = $row;
                }
            }

            return new SqlResult(
                $rows,
                $statement->rowCount(),
            );
        } catch (PDOException) {
            // Driver errors may contain SQL or parameter values.
            // Do not chain the PDO exception into public errors.
            throw new QueryException(
                'Database query failed.',
            );
        }
    }

    public function beginTransaction(): void
    {
        $this->connect();
        $pdo = $this->requirePdo();

        try {
            if ($pdo->inTransaction()) {
                throw new TransactionException(
                    'Nested database transactions are not supported.',
                );
            }

            if (! $pdo->beginTransaction()) {
                throw new TransactionException(
                    'Database transaction could not start.',
                );
            }
        } catch (PDOException) {
            throw new TransactionException(
                'Database transaction could not start.',
            );
        }
    }

    public function commit(): void
    {
        $pdo = $this->requireConnectedTransaction();

        try {
            $succeeded = $pdo->commit();
        } catch (PDOException) {
            $succeeded = false;
        }

        if (! $succeeded) {
            // A failed commit may have an indeterminate outcome.
            // Never retry automatically.
            $this->abandonConnection();

            throw new TransactionException(
                'Database commit failed.',
            );
        }
    }

    public function rollBack(): void
    {
        $pdo = $this->requireConnectedTransaction();

        try {
            $succeeded = $pdo->rollBack();
        } catch (PDOException) {
            $succeeded = false;
        }

        if (! $succeeded) {
            $this->abandonConnection();

            throw new TransactionException(
                'Database rollback failed.',
            );
        }
    }

    public function inTransaction(): bool
    {
        if ($this->pdo === null) {
            return false;
        }

        try {
            return $this->pdo->inTransaction();
        } catch (PDOException) {
            throw new TransactionException(
                'Database transaction state unavailable.',
            );
        }
    }

    /**
     * @template T
     * @param Closure(SqlConnection): T $operation
     * @return T
     */
    public function transactional(
        Closure $operation,
    ): mixed {
        $this->beginTransaction();

        try {
            $value = $operation($this);
        } catch (Throwable $exception) {
            try {
                $this->rollBack();
            } catch (Throwable) {
                try {
                    $this->disconnect();
                } catch (Throwable) {
                    // Handle is detached; preserve sanitized error.
                }

                throw new TransactionException(
                    'Database rollback failed.',
                );
            }

            // The original callback exception remains observable
            // to the calling application after successful rollback.
            throw $exception;
        }

        $this->commit();

        return $value;
    }

    public function lastInsertId(): string
    {
        $pdo = $this->requirePdo();

        try {
            $id = $pdo->lastInsertId();
        } catch (PDOException) {
            throw new QueryException(
                'Cannot retrieve generated identifier.',
            );
        }

        if ($id === false) {
            throw new QueryException(
                'Cannot retrieve generated identifier.',
            );
        }

        return $id;
    }

    private function abandonConnection(): void
    {
        try {
            $this->disconnect();
        } catch (TransactionException) {
            // disconnect() always detaches the handle first.
        }
    }

    private function requirePdo(): PDO
    {
        if ($this->pdo === null) {
            throw new ConnectionException(
                'Database is not connected.',
            );
        }

        return $this->pdo;
    }

    private function requireConnectedTransaction(): PDO
    {
        if ($this->pdo === null) {
            throw new TransactionException(
                'No active database transaction.',
            );
        }

        $pdo = $this->pdo;

        try {
            if (! $pdo->inTransaction()) {
                throw new TransactionException(
                    'No active database transaction.',
                );
            }
        } catch (PDOException) {
            throw new TransactionException(
                'Database transaction state unavailable.',
            );
        }

        return $pdo;
    }
}
