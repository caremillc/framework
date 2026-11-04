<?php

declare(strict_types=1);

namespace Careminate\Database\Migrations;

use Careminate\Database\SqlConnection;
use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * MySQL-only migration runner.
 *
 * MySQL DDL is not assumed to be transactionally reversible.
 * A "running" ledger record must be reconciled manually
 * after an interrupted or failed migration.
 */
final class MySqlMigrationRunner
{
    private readonly MigrationCatalog $catalog;

    public function __construct(
        private readonly SqlConnection $database,
        string $migrationDirectory,
        private readonly string $historyTable = 'careminate_migrations',
        private readonly int $lockTimeoutSeconds = 10,
    ) {
        if ($database->driver() !== 'mysql') {
            throw new InvalidArgumentException(
                'MySQL migration runner requires MySQL.',
            );
        }

        if (
            preg_match(
                '/\A[a-z][a-z0-9_]{0,59}\z/D',
                $historyTable,
            ) !== 1
            || $lockTimeoutSeconds < 0
            || $lockTimeoutSeconds > 30
        ) {
            throw new InvalidArgumentException(
                'Invalid migration runner configuration.',
            );
        }

        $this->catalog = new MigrationCatalog(
            $migrationDirectory,
        );
    }

    /**
     * Read-only status; does not create the tracking table.
     *
     * @return list<array{
     *     name: string,
     *     status: string,
     *     batch: int|null
     * }>
     */
    public function status(): array
    {
        $files = $this->catalog->discover();
        $rows = $this->readHistory(false);

        $this->validateHistory($files, $rows);

        $result = [];

        foreach ($files as $name => $file) {
            $row = $rows[$name] ?? null;

            $result[] = [
                'name' => $name,
                'status' => $row['status'] ?? 'pending',
                'batch' => $row['batch'] ?? null,
            ];
        }

        return $result;
    }

    /** Return the number of newly applied migrations. */
    public function migrate(): int
    {
        if ($this->database->inTransaction()) {
            throw new MigrationException(
                'Cannot migrate inside a transaction.',
            );
        }

        $files = $this->catalog->discover();
        $lock = $this->lockName();

        $this->acquireLock($lock);

        $failure = null;
        $applied = 0;

        try {
            $this->ensureHistoryTable();

            $history = $this->readHistory(true);

            $this->validateHistory($files, $history);

            foreach ($history as $record) {
                if ($record['status'] !== 'applied') {
                    throw new MigrationException(
                        'An incomplete migration requires manual reconciliation.',
                    );
                }
            }

            $batch = 1;

            foreach ($history as $record) {
                $batch = max(
                    $batch,
                    $record['batch'] + 1,
                );
            }

            foreach ($files as $name => $file) {
                if (isset($history[$name])) {
                    continue;
                }

                $this->assertLockOwned($lock);

                $actualChecksum = hash_file(
                    'sha256',
                    $file['path'],
                );

                if (
                    $actualChecksum !== $file['checksum']
                ) {
                    throw new MigrationException(
                        'Migration file changed during execution.',
                    );
                }

                // Persist before running any DDL.
                $this->database->execute(
                    'INSERT INTO `' . $this->historyTable . '` '
                        . '(name, checksum, status, batch, started_at) '
                        . 'VALUES (:name, :checksum, :status, :batch, UTC_TIMESTAMP(6))',
                    [
                        'name' => $name,
                        'checksum' => $file['checksum'],
                        'status' => 'running',
                        'batch' => $batch,
                    ],
                );

                try {
                    $callback = (
                        static fn (string $path): mixed =>
                            require $path
                    )($file['path']);

                    if (! $callback instanceof Closure) {
                        throw new MigrationException(
                            'Migration must return a Closure.',
                        );
                    }

                    $callback($this->database);

                    $this->assertLockOwned($lock);

                    $updated = $this->database->execute(
                        'UPDATE `' . $this->historyTable . '` '
                            . 'SET status = :applied, applied_at = UTC_TIMESTAMP(6) '
                            . 'WHERE name = :name AND status = :running',
                        [
                            'applied' => 'applied',
                            'name' => $name,
                            'running' => 'running',
                        ],
                    );

                    if ($updated->affectedRows !== 1) {
                        throw new MigrationException(
                            'Migration ledger update failed.',
                        );
                    }
                } catch (Throwable) {
                    throw new MigrationException(
                        'Migration execution incomplete; manual reconciliation required.',
                    );
                }

                ++$applied;
            }
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        // Release regardless of migration success or failure.
        try {
            $this->releaseLock($lock);
        } catch (Throwable) {
            $failure ??= new MigrationException(
                'Migration lock release failed.',
            );
        }

        if ($failure !== null) {
            if ($failure instanceof MigrationException) {
                throw $failure;
            }

            throw new MigrationException(
                'Migration failed; inspect the migration ledger.',
            );
        }

        return $applied;
    }

    /**
     * Stable lock name scoped to a database schema and ledger.
     */
    public function lockName(): string
    {
        $name = $this->database
            ->execute('SELECT DATABASE() AS db_name')
            ->first()['db_name'] ?? null;

        if (! is_string($name) || $name === '') {
            throw new MigrationException(
                'No MySQL database selected.',
            );
        }

        return 'careminate:migrate:'
            . substr(
                hash(
                    'sha256',
                    $name . ':' . $this->historyTable,
                ),
                0,
                32,
            );
    }

    private function acquireLock(string $lock): void
    {
        $value = $this->database->execute(
            'SELECT GET_LOCK(:lock_name, :wait_seconds) AS acquired',
            [
                'lock_name' => $lock,
                'wait_seconds' => $this->lockTimeoutSeconds,
            ],
        )->first()['acquired'] ?? null;

        if ($value !== 1 && $value !== '1') {
            throw new MigrationException(
                'Migration lock unavailable.',
            );
        }
    }

    private function assertLockOwned(
        string $lock,
    ): void {
        $value = $this->database->execute(
            'SELECT IF(IS_USED_LOCK(:lock_name) = CONNECTION_ID(), 1, 0) AS owned',
            ['lock_name' => $lock],
        )->first()['owned'] ?? null;

        if ($value !== 1 && $value !== '1') {
            throw new MigrationException(
                'Migration lock ownership lost.',
            );
        }
    }

    private function releaseLock(
        string $lock,
    ): void {
        $value = $this->database->execute(
            'SELECT RELEASE_LOCK(:lock_name) AS released',
            ['lock_name' => $lock],
        )->first()['released'] ?? null;

        if ($value !== 1 && $value !== '1') {
            throw new MigrationException(
                'Migration lock release failed.',
            );
        }
    }

    private function ensureHistoryTable(): void
    {
        $this->database->execute(
            'CREATE TABLE IF NOT EXISTS `'
                . $this->historyTable . '` ('
                . 'name VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,'
                . 'checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
                . "status ENUM('running','applied') NOT NULL,"
                . 'batch INT UNSIGNED NOT NULL,'
                . 'started_at DATETIME(6) NOT NULL,'
                . 'applied_at DATETIME(6) NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
    }

    /**
     * @return array<string, array{
     *     checksum: string,
     *     status: string,
     *     batch: int
     * }>
     */
    private function readHistory(
        bool $tableExists,
    ): array {
        if (! $tableExists) {
            $found = $this->database->execute(
                'SELECT COUNT(*) AS total '
                    . 'FROM information_schema.tables '
                    . 'WHERE table_schema = DATABASE() '
                    . 'AND table_name = :table_name',
                [
                    'table_name' => $this->historyTable,
                ],
            )->first()['total'] ?? null;

            if ($found === 0 || $found === '0') {
                return [];
            }
        }

        $records = [];

        $rows = $this->database->execute(
            'SELECT name, checksum, status, batch '
                . 'FROM `' . $this->historyTable . '` '
                . 'ORDER BY name',
        )->rows();

        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            $checksum = $row['checksum'] ?? null;
            $status = $row['status'] ?? null;
            $batch = $row['batch'] ?? null;

            if (
                ! is_string($name)
                || ! is_string($checksum)
                || ! is_string($status)
                || ! in_array(
                    $status,
                    ['running', 'applied'],
                    true,
                )
                || (
                    ! is_int($batch)
                    && ! is_string($batch)
                )
                || ! ctype_digit((string) $batch)
                || (int) $batch < 1
            ) {
                throw new MigrationException(
                    'Invalid migration ledger data.',
                );
            }

            $records[$name] = [
                'checksum' => $checksum,
                'status' => $status,
                'batch' => (int) $batch,
            ];
        }

        return $records;
    }

    /**
     * @param array<string, array{
     *     path: string,
     *     checksum: string
     * }> $files
     * @param array<string, array{
     *     checksum: string,
     *     status: string,
     *     batch: int
     * }> $history
     */
    private function validateHistory(
        array $files,
        array $history,
    ): void {
        foreach ($history as $name => $record) {
            if (! isset($files[$name])) {
                throw new MigrationException(
                    'Applied migration file is missing.',
                );
            }

            if (
                ! hash_equals(
                    $record['checksum'],
                    $files[$name]['checksum'],
                )
            ) {
                throw new MigrationException(
                    'Migration checksum mismatch.',
                );
            }
        }
    }
}
