<?php 
namespace Careminate\Databases\Drivers;

use PDO;
use Exception;
use Careminate\Logs\Log;
use Careminate\Databases\Contracts\DatabaseConnectionInterface;

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
        $config = config('database.drivers');
// var_dump($config);
        $this->port = $config['mysql']['port'];
        $this->host = $config['mysql']['host'] . ':' . $this->port;
        $this->database = $config['mysql']['database'];
        $this->charset = $config['mysql']['charset'];
        $this->username = $config['mysql']['username'];
        $this->password = $config['mysql']['password'];
        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->database . ";charset=" . $this->charset;
            $this->pdo = new PDO($dsn, $this->username, $this->password);
            $this->pdo->setAttribute($config['mysql']['ERRMODE'], $config['mysql']['EXCEPTION']);
        } catch (Exception $e) {
            throw new Log($e->getMessage());
        }
    }


    public function getPDO(): PDO
    {
        return $this->pdo;
    }
}
