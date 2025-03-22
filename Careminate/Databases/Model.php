<?php 

namespace Careminate\Databases;

use Careminate\Databases\QueryBuilder;
use Careminate\Support\Pagination\Paginator;

abstract class Model
{
    protected static string $table;
    protected array $fillable = [];
    protected array $attributes = [];

    public function __get(string $name)
    {
        return $this->attributes[$name] ?? null;
    }

    public function __set(string $name, $value): void
    {
        if (in_array($name, $this->fillable)) {
            $this->attributes[$name] = $value;
        }
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
        $query = new QueryBuilder(static::$table);
        // Implement insert/update logic
    }

    // Add other methods (update, delete, etc)
}

