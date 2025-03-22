<?php 

namespace Careminate\Databases;

use Careminate\Databases\QueryBuilder\QueryBuilder;
use Careminate\Support\Pagination\Paginator;
use Careminate\Databases\Connection;

abstract class Model
{
    protected static string $table;
    protected array $fillable = [];
    protected array $attributes = [];
    protected static Connection $connection;

    public static function setConnection(Connection $connection): void
    {
        self::$connection = $connection;
    }

    public static function create(array $attributes): static
    {
        $model = new static();
        foreach ($attributes as $key => $value) {
            $model->$key = $value;
        }
        $model->save();
        return $model;
    }

    public function save(): void
    {
        $query = new QueryBuilder(static::$table, self::$connection);
        // Implement insert/update logic with QueryBuilder.
    }

    // Add other methods (update, delete, etc)
}
