<?php

declare(strict_types=1);

namespace Flint\Tests\Unit\Database;

use Flint\Database\MySqlConnectionFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MySqlConnectionFactoryTest extends TestCase
{
    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsAreRejectedBeforeConnecting(
        string $database,
        string $username,
        int $port,
    ): void {
        $factory = new MySqlConnectionFactory();

        $this->expectException(InvalidArgumentException::class);

        $factory->connect(
            database: $database,
            username: $username,
            password: 'unused',
            port: $port,
        );
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'empty database' => ['', 'user', 3306];
        yield 'DSN injection' => ['tasks;charset=latin1', 'user', 3306];
        yield 'database null byte' => ["tasks\0", 'user', 3306];
        yield 'database too long' => [str_repeat('a', 65), 'user', 3306];
        yield 'blank username' => ['tasks', ' ', 3306];
        yield 'username null byte' => ['tasks', "user\0", 3306];
        yield 'port zero' => ['tasks', 'user', 0];
        yield 'port above maximum' => ['tasks', 'user', 65536];
    }
}
