<?php

namespace Careminate\Databases\Dbal;

use PDO;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Careminate\Support\helpers; // Access helper functions like env()

class ConnectionFactory
{
    private string $driver; // Holds the selected database driver

    public function __construct(private array $config)
    {
        // Set the selected driver from the config
        $this->driver = $this->config['driver'];
    }

    public function create(): Connection
    {
        $dbConfig = $this->config['drivers'][$this->driver];

        $connectionParams = [
            'url' => $this->buildConnectionUrl($dbConfig),
            'driver' => $dbConfig['engine'],
            'user' => $dbConfig['username'],
            'password' => $dbConfig['password'],
            'dbname' => $dbConfig['database'],
            'charset' => $dbConfig['charset'],
            'driverOptions' => [
                PDO::ATTR_ERRMODE => $dbConfig['ERRMODE'],
                PDO::ATTR_DEFAULT_FETCH_MODE => $dbConfig['FETCH_MODE'],
            ]
        ];

        return DriverManager::getConnection($connectionParams);
    }

    private function buildConnectionUrl(array $dbConfig): string
    {
        // Build the database connection URL depending on the engine
        switch ($dbConfig['engine']) {
            case 'sqlite':
                return "sqlite:{$dbConfig['path']}";
            case 'mysql':
                return "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']}";
            case 'pgsql':
                return "pgsql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']}";
            default:
                throw new \RuntimeException("Unsupported database driver: {$dbConfig['engine']}");
        }
    }

    // New method to get the currently connected database driver
    public function getConnectedDriver(): string
    {
        return $this->driver;  // Return the selected driver
    }
}
