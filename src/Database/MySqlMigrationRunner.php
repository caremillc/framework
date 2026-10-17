<?php

declare(strict_types=1);

namespace Flint\Database;

use Closure;
use Flint\Database\Exception\MigrationExecutionException;
use Flint\Database\Exception\TransactionRollbackException;
use InvalidArgumentException;
use LogicException;
use PDO;
use RuntimeException;
use Throwable;

final readonly class MySqlMigrationRunner
{
    public function __construct(
        private PDO $connection,
        private string $historyTable = 'flint_migrations',
    ) {
        if (
            preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/', $historyTable) !== 1
        ) {
            throw new InvalidArgumentException(
                'Migration history table names must be simple SQL identifiers.',
            );
        }
    }

    /**
     * @return list<string> Newly applied migration identifiers.
     */
    public function migrate(string $directory): array
    {
        if ($this->connection->inTransaction()) {
            throw new LogicException(
                'Migrations require a connection without an active transaction.',
            );
        }

        $lock = new MySqlMigrationLock($this->connection);
        $lock->acquire();

        $operationFailure = null;

        try {
            return $this->applyPending($directory);
        } catch (Throwable $exception) {
            $operationFailure = $exception;

            throw $exception;
        } finally {
            try {
                $lock->release();
            } catch (Throwable $releaseFailure) {
                if ($operationFailure !== null) {
                    throw new MigrationExecutionException(
                        $operationFailure,
                        $releaseFailure,
                    );
                }

                throw $releaseFailure;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function applyPending(string $directory): array
    {
        $migrations = $this->discover($directory);
        $table = $this->historyTable;

        $this->connection->exec(
            "CREATE TABLE IF NOT EXISTS `$table` (
                migration_id VARCHAR(190)
                    CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                checksum CHAR(64)
                    CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                state VARCHAR(16) NOT NULL,
                started_at DATETIME NOT NULL,
                applied_at DATETIME NULL,
                PRIMARY KEY (migration_id)
            ) ENGINE=InnoDB",
        );

        $statement = $this->connection->query(
            "SELECT migration_id, checksum, state FROM `$table`
             ORDER BY migration_id",
        );

        if ($statement === false) {
            throw new RuntimeException('Could not read migration history.');
        }

        /** @var list<array{migration_id: string, checksum: string, state: string}> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        $applied = [];

        foreach ($rows as $row) {
            $id = $row['migration_id'];

            if (!isset($migrations[$id])) {
                throw new RuntimeException(
                    'Migration history references a missing migration: ' . $id,
                );
            }

            if (!hash_equals($row['checksum'], $migrations[$id]['checksum'])) {
                throw new RuntimeException(
                    'A recorded migration has changed: ' . $id,
                );
            }

            if ($row['state'] !== 'applied') {
                throw new RuntimeException(
                    'An unfinished migration requires investigation: ' . $id,
                );
            }

            $applied[$id] = true;
        }

        $latest = $rows === []
            ? null
            : $rows[array_key_last($rows)]['migration_id'];

        // Validate ordering before executing any pending migration.
        foreach ($migrations as $id => $migration) {
            if (
                !isset($applied[$id])
                && $latest !== null
                && strcmp($id, $latest) <= 0
            ) {
                throw new RuntimeException(
                    'A pending migration precedes recorded history: ' . $id,
                );
            }
        }

        $completed = [];

        foreach ($migrations as $id => $migration) {
            if (isset($applied[$id])) {
                continue;
            }

            // Load the trusted file in an isolated variable scope.
            $callback = (static function (string $path): mixed {
                return require $path;
            })($migration['path']);

            if (!$callback instanceof Closure) {
                throw new RuntimeException(
                    'Migration files must return a closure: ' . $id,
                );
            }

            if (
                hash_file('sha256', $migration['path'])
                !== $migration['checksum']
            ) {
                throw new RuntimeException(
                    'A migration file changed during execution preparation: ' . $id,
                );
            }

            $statement = $this->connection->prepare(
                "INSERT INTO `$table`
                    (migration_id, checksum, state, started_at)
                 VALUES (:id, :checksum, 'running', UTC_TIMESTAMP())",
            );

            if ($statement === false) {
                throw new RuntimeException('Could not prepare migration history.');
            }

            $statement->execute([
                'id' => $id,
                'checksum' => $migration['checksum'],
            ]);

            $statement->closeCursor();

            try {
                $callback($this->connection);

                if ($this->connection->inTransaction()) {
                    throw new LogicException(
                        'Migration callbacks must not leave an active transaction.',
                    );
                }
            } catch (Throwable $failure) {
                try {
                    if ($this->connection->inTransaction()) {
                        if (!$this->connection->rollBack()) {
                            throw new RuntimeException(
                                'Could not roll back the migration transaction.',
                            );
                        }
                    }
                } catch (Throwable $rollbackFailure) {
                    throw new TransactionRollbackException(
                        $failure,
                        $rollbackFailure,
                    );
                }

                // Keep the persistent "running" marker for operator review.
                throw $failure;
            }

            $statement = $this->connection->prepare(
                "UPDATE `$table`
                 SET state = 'applied', applied_at = UTC_TIMESTAMP()
                 WHERE migration_id = :id AND state = 'running'",
            );

            if ($statement === false) {
                throw new RuntimeException('Could not prepare migration completion.');
            }

            $statement->execute(['id' => $id]);
            $updated = $statement->rowCount();
            $statement->closeCursor();

            if ($updated !== 1) {
                throw new RuntimeException(
                    'Migration completion could not be recorded: ' . $id,
                );
            }

            $completed[] = $id;
        }

        return $completed;
    }

    /**
     * @return array<string, array{path: string, checksum: string}>
     */
    private function discover(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new InvalidArgumentException(
                'The migration directory must exist.',
            );
        }

        $paths = glob(rtrim($directory, '/\\') . '/*.php');

        if ($paths === false) {
            throw new RuntimeException('Could not inspect migration files.');
        }

        sort($paths, SORT_STRING);

        $migrations = [];

        foreach ($paths as $path) {
            $id = basename($path, '.php');

            if (
                preg_match('/\A[0-9]{14}_[a-z][a-z0-9_]{0,99}\z/', $id) !== 1
            ) {
                throw new RuntimeException(
                    'Migration filenames must use YYYYMMDDHHMMSS_description.php.',
                );
            }

            $checksum = hash_file('sha256', $path);

            if ($checksum === false) {
                throw new RuntimeException('Could not checksum migration: ' . $id);
            }

            $migrations[$id] = [
                'path' => $path,
                'checksum' => $checksum,
            ];
        }

        return $migrations;
    }
}
