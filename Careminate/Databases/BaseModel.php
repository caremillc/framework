<?php 
namespace Careminate\Databases;

use PDO;
use Careminate\Databases\Drivers\MySQLConnection;
use Careminate\Databases\Drivers\SQLiteConnection;
use Careminate\Databases\Contracts\DatabaseConnectionInterface;

abstract class BaseModel
{
    protected static PDO $db;
    protected $table;
    protected $attributes = [];

    public function __construct()
    {
        self::initializeDB();
    }
    
    protected static function initializeDB()
    {
        if (!isset(self::$db)) {
            $driver = config('database.driver');
            $drivers = config('database.drivers');
            switch ($driver) {
                case 'mysql':
                    $connection = new MySQLConnection();
                    break;
                case 'sqlite':
                    $connection = new SQLiteConnection();
                    break;
                default:
                    throw new \RuntimeException("Unsupported database driver: {$driver}");
            }
            self::$db = $connection->getPDO();
        }
    }


    /**
     * get database driver settings
     * @return object 
     */
    public static function getDBConf(): object
    {
        $driver = config('database.driver');
        return (object) config('database.drivers')[$driver];
    }

    // public static function setAttributes($attributes)
    // {
    //     self::$attributes = $attributes;
    // }

    public function setAttributes($attributes)
    {
        $this->attributes = $attributes;
    }


    /**
     * to get a current property from table in database
     * @param mixed $name
     *
     * @return mixed
     */
    // public function __get($name): mixed
    // {
    //     return self::$attributes[$name] ?? null;
    // }

    public function __get($name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    /**
     * to set a new dynamic property
     * @param string $name
     * @param mixed $value
     *
     * @return void
     */
    // public function __set(string $name, $value): void
    // {
    //     self::$attributes[$name] = $value;
    // }

    public function __set(string $name, $value): void
    {
        $this->attributes[$name] = $value;
    }
}
