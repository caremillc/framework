<?php

declare(strict_types=1);

namespace Flint\Database;

use Flint\Database\Exception\MigrationLockException;
use LogicException;
use PDO;
use RuntimeException;
use Throwable;

final class MySqlMigrationLock
{
    private bool $held = false;

    private bool $usable = true;

    private ?string $name = null;

    public function __construct(
        private readonly PDO $connection,
    ) {
    }

    public function acquire(): void
    {
        if (!$this->usable) {
            throw new LogicException(
                'The migration lock is unusable. Discard its connection.',
            );
        }

        if ($this->held) {
            throw new LogicException(
                'This migration lock is already held.',
            );
        }

        $statement = $this->connection->query('SELECT DATABASE()');

        if ($statement === false) {
            throw new RuntimeException(
                'Could not determine the migration database.',
            );
        }

        $database = $statement->fetchColumn();
        $statement->closeCursor();

        if (!is_string($database) || $database === '') {
            throw new MigrationLockException(
                'Migration locking requires a selected database.',
            );
        }

        // Conservative case normalization also serializes case-only names.
        $name = 'flint:migrations:'
            . substr(hash('sha256', strtolower($database)), 0, 40);

        // MySQL permits recursive acquisition on the same session.
        // Reject it even when another lock object owns that acquisition.
        $statement = $this->connection->prepare(
            'SELECT IS_USED_LOCK(:name) = CONNECTION_ID()',
        );

        if ($statement === false) {
            throw new RuntimeException('Could not inspect migration lock ownership.');
        }

        $statement->execute(['name' => $name]);
        $ownedByThisSession = $statement->fetchColumn();
        $statement->closeCursor();

        if ((string) $ownedByThisSession === '1') {
            throw new LogicException(
                'This connection already owns the database migration lock.',
            );
        }

        $statement = $this->connection->prepare(
            'SELECT GET_LOCK(:name, 0)',
        );

        if ($statement === false) {
            throw new RuntimeException('Could not prepare migration locking.');
        }

        $statement->execute(['name' => $name]);
        $result = $statement->fetchColumn();
        $statement->closeCursor();

        if ((string) $result !== '1') {
            throw new MigrationLockException(
                'The migration lock is unavailable. Another migration may be running.',
            );
        }

        $this->name = $name;
        $this->held = true;
    }

    public function release(): void
    {
        if (!$this->usable) {
            throw new LogicException(
                'The migration lock is unusable. Discard its connection.',
            );
        }

        if (!$this->held) {
            return;
        }

        try {
            $statement = $this->connection->prepare(
                'SELECT RELEASE_LOCK(:name)',
            );

            if ($statement === false) {
                throw new RuntimeException(
                    'Could not prepare migration lock release.',
                );
            }

            $statement->execute(['name' => $this->name]);
            $result = $statement->fetchColumn();
            $statement->closeCursor();

            if ((string) $result !== '1') {
                throw new MigrationLockException(
                    'Migration lock ownership could not be released reliably.',
                );
            }
        } catch (Throwable $exception) {
            $this->usable = false;

            throw $exception;
        }

        $this->held = false;
        $this->name = null;
    }
}
