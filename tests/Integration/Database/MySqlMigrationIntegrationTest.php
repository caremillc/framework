<?php

declare(strict_types=1);

namespace Careminate\Tests\Integration\Database;

use Careminate\Database\ConnectionDefinition;
use Careminate\Database\Migrations\MigrationException;
use Careminate\Database\Migrations\MySqlMigrationRunner;
use Careminate\Database\MySql\MySqlConnection;
use PHPUnit\Framework\TestCase;

final class MySqlMigrationIntegrationTest extends TestCase
{
    private MySqlConnection $database;

    private ConnectionDefinition $definition;

    private string $directory;

    private string $historyTable;

    private string $dataTable;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_mysql')) {
            self::markTestSkipped(
                'pdo_mysql is required.',
            );
        }

        $host = getenv('CAREMINATE_TEST_MYSQL_HOST');
        $schema = getenv('CAREMINATE_TEST_MYSQL_DATABASE');
        $user = getenv('CAREMINATE_TEST_MYSQL_USERNAME');
        $password = getenv('CAREMINATE_TEST_MYSQL_PASSWORD');
        $port = getenv('CAREMINATE_TEST_MYSQL_PORT');

        if (
            ! is_string($host) || $host === ''
            || ! is_string($schema) || $schema === ''
            || ! is_string($user) || $user === ''
            || ! is_string($password) || $password === ''
        ) {
            self::markTestSkipped(
                'Dedicated MySQL test credentials are required.',
            );
        }

        $this->definition = new ConnectionDefinition(
            'mysql',
            [
                'host' => $host,
                'port' => is_string($port)
                    && ctype_digit($port)
                        ? (int) $port
                        : 3306,
                'database' => $schema,
                'username' => $user,
                'password' => $password,
                'connect_timeout' => 3,
            ],
        );

        $this->database = MySqlConnection::fromDefinition(
            $this->definition,
        );

        $this->database->connect();

        $suffix = bin2hex(random_bytes(6));

        $this->historyTable = 'cm_mig_' . $suffix;
        $this->dataTable = 'cm_data_' . $suffix;

        $this->directory = sys_get_temp_dir()
            . '/careminate-day27-' . $suffix;

        self::assertTrue(
            mkdir($this->directory, 0o700),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            try {
                $this->database->execute(
                    'DROP TABLE IF EXISTS `'
                        . $this->dataTable . '`',
                );

                $this->database->execute(
                    'DROP TABLE IF EXISTS `'
                        . $this->historyTable . '`',
                );
            } finally {
                $this->database->disconnect();
            }
        }

        if (
            isset($this->directory)
            && is_dir($this->directory)
        ) {
            $paths = glob($this->directory . '/*');

            if (is_array($paths)) {
                foreach ($paths as $path) {
                    unlink($path);
                }
            }

            rmdir($this->directory);
        }
    }

    public function testFreshApplyStatusAndRerun(): void
    {
        $this->writeMigration(
            '20261104_000001_create_example',
            'CREATE TABLE `' . $this->dataTable
                . '` (id INT PRIMARY KEY) ENGINE=InnoDB',
        );

        $runner = $this->runner();

        self::assertSame(
            'pending',
            $runner->status()[0]['status'],
        );

        self::assertSame(1, $runner->migrate());

        self::assertSame(
            'applied',
            $runner->status()[0]['status'],
        );

        self::assertSame(0, $runner->migrate());
    }

    public function testFailedMigrationBlocksAutomaticRetry(): void
    {
        $this->writeMigration(
            '20261104_000001_broken',
            'INVALID SQL STATEMENT',
        );

        $runner = $this->runner();

        try {
            $runner->migrate();

            self::fail('Broken migration must fail.');
        } catch (MigrationException $exception) {
            self::assertStringContainsString(
                'reconciliation',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            'running',
            $runner->status()[0]['status'],
        );

        $this->expectException(
            MigrationException::class,
        );

        $runner->migrate();
    }

    public function testChecksumsRejectEditedAppliedMigration(): void
    {
        $path = $this->writeMigration(
            '20261104_000001_create_example',
            'CREATE TABLE `' . $this->dataTable
                . '` (id INT PRIMARY KEY) ENGINE=InnoDB',
        );

        $runner = $this->runner();

        self::assertSame(1, $runner->migrate());

        file_put_contents(
            $path,
            "<?php\n// changed after deployment\n"
                . file_get_contents($path),
        );

        $this->expectException(
            MigrationException::class,
        );

        $this->expectExceptionMessage(
            'checksum mismatch',
        );

        $runner->migrate();
    }

    public function testAnotherSessionCannotRunWhileLockHeld(): void
    {
        $this->writeMigration(
            '20261104_000001_create_example',
            'CREATE TABLE `' . $this->dataTable
                . '` (id INT PRIMARY KEY) ENGINE=InnoDB',
        );

        $runner = $this->runner(lockTimeout: 0);

        $locker = MySqlConnection::fromDefinition(
            $this->definition,
        );

        $lock = $runner->lockName();

        try {
            $value = $locker->execute(
                'SELECT GET_LOCK(:lock_name, 0) AS acquired',
                ['lock_name' => $lock],
            )->first()['acquired'] ?? null;

            self::assertTrue(
                $value === 1 || $value === '1',
            );

            $this->expectException(
                MigrationException::class,
            );

            $this->expectExceptionMessage(
                'Migration lock unavailable.',
            );

            $runner->migrate();
        } finally {
            $locker->disconnect();
        }
    }

    private function runner(
        int $lockTimeout = 10,
    ): MySqlMigrationRunner {
        return new MySqlMigrationRunner(
            $this->database,
            $this->directory,
            $this->historyTable,
            $lockTimeout,
        );
    }

    private function writeMigration(
        string $name,
        string $sql,
    ): string {
        $path = $this->directory
            . '/' . $name . '.php';

        file_put_contents(
            $path,
            "<?php\ndeclare(strict_types=1);\n"
                . 'return static function (\Careminate\Database\SqlConnection $db): void {'
                . "\n"
                . '    $db->execute('
                . var_export($sql, true)
                . ');'
                . "\n"
                . "};\n",
        );

        return $path;
    }
}
