<?php
namespace Careminate\Databases\Drivers;

use Careminate\Databases\Contracts\DatabaseConnectionInterface;
use Exception;
use PDO;

class MySQLConnection implements DatabaseConnectionInterface
{
    private PDO $pdo;
    protected string $username;
    protected string $password;
    protected string $database;
    protected string $charset;
    protected string $host;
    protected string|int $port;

    public function __construct()
    {
        $config         = config('database.drivers');
        $this->host     = $config['mysql']['host'];
        $this->port     = $config['mysql']['port'];
        $this->database = $config['mysql']['database'];
        $this->charset  = $config['mysql']['charset'];
        $this->username = $config['mysql']['username'];
        $this->password = $config['mysql']['password'];
        try {
            $dsn       = "mysql:host={$this->host};port={$this->port};dbname={$this->database};charset={$this->charset}";
            $this->pdo = new PDO($dsn, $this->username, $this->password);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Use constants directly
        } catch (Exception $e) {
            error_log($e->getMessage()); // Or use a logging class
            throw new \RuntimeException("Database connection error: " . $e->getMessage(), 0, $e);
        }
    }

    public function getPDO(): PDO
    {
        return $this->pdo;
    }
}
