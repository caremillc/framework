<?php 
namespace Careminate\Databases\Drivers;

use PDO;
use Careminate\Logs\Log;
use Careminate\Databases\Contracts\DatabaseConnectionInterface;

class SQLiteConnection implements DatabaseConnectionInterface
{
    private PDO $pdo;
    protected $path;

    public function __construct()
    {
        $config = config('database.drivers');
        $this->path = $config['sqlite']['path'];
        $dsn = "sqlite:" . $this->path;
        try {
            $this->pdo = new PDO($dsn);
            $this->pdo->setAttribute($config['sqlite']['ERRMODE'], $config['sqlite']['EXCEPTION']);
        }catch (\Exception $e) {
            error_log($e->getMessage()); // Or use a logging class
            throw new \RuntimeException("Database connection error: " . $e->getMessage(), 0, $e);
        }
    }


    public function getPDO(): PDO
    {
        return $this->pdo;
    }
}
