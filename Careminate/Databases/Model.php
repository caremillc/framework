<?php
namespace Careminate\Databases;

use Careminate\Databases\Drivers\MySQLConnection;
use Careminate\Databases\Drivers\SQLiteConnection;
use Careminate\Logs\Log;

class Model extends BaseModel
{
    // use DBConditions, DBSelector;
    public function __construct()
    {
        $config = config('database.driver');
        if ($config == 'mysql') {
            parent::__construct(new MySQLConnection());
            echo "mysql connection is on ";
        } elseif ($config == 'sqlite') {
            parent::__construct(new SQLiteConnection());
            echo "sqlite connection is on ";
        } else {
            throw new Log('Database driver not supported');
        }
    }

    public static function getTable()
    {
        $class = new static;
        if ($class->table == null) {
            $class->table = strtolower((new \ReflectionClass(static::class))->getShortName()) . 's';
        }
        return $class->table;
    }

    public function toArray()
    {
        return (array) static::$attributes;
    }
}
