<?php 
namespace Careminate\Databases;

use PDO;
use Careminate\Databases\Contracts\DatabaseConnectionInterface;

abstract class BaseModel
{
    protected static PDO $db;
    protected $table;
    protected static $attributes = [];

    public function __construct(DatabaseConnectionInterface $connect)
    {
        self::$db = $connect->getPDO();
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

    public static function setAttributes($attributes)
    {
        self::$attributes = $attributes;
    }



    /**
     * to get a current property from table in database
     * @param mixed $name
     *
     * @return mixed
     */
    public function __get($name): mixed
    {
        return self::$attributes[$name] ?? null;
    }

    /**
     * to set a new dynamic property
     * @param string $name
     * @param mixed $value
     *
     * @return void
     */
    public function __set(string $name, $value): void
    {
        self::$attributes[$name] = $value;
    }
}
