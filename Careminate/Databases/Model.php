<?php
namespace Careminate\Databases;

use Careminate\Logs\Log;
use Careminate\Databases\Drivers\MySQLConnection;
use Careminate\Databases\QueryBuilder\DBSelector;
use Careminate\Databases\Drivers\SQLiteConnection;
use Careminate\Databases\QueryBuilder\DBCondition;

class Model extends BaseModel
{
    use DBCondition, DBSelector;

    public function __construct()
    {
        $config = config('database.driver');
        if ($config == 'mysql') {
            parent::__construct(new MySQLConnection());
            //echo "MySQL connection is on ";
        } elseif ($config == 'sqlite') {
            parent::__construct(new SQLiteConnection());
           // echo "SQLite connection is on ";
        } else {
            throw new Log('Database driver not supported');
        }
    }

    public static function getTable()
    {
        $class = new static;
        // Check if the 'table' property is set
        if (isset($class->table)) {
            return $class->table;
        } else {
            // Generate a default table name based on the class name
            return strtolower((new \ReflectionClass(static::class))->getShortName()) . 's';
        }
    }

    public function toArray()
    {
        return (array) static::$attributes;
    }
}
 