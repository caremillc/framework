<?php
namespace Careminate\Databases\QueryBuilder;

use Careminate\Databases\Pagination\Paginator;

trait DBSelector
{

    // Declare the static $conditions property
    protected static array $conditions = [];
    /**
     * @param int $id
     *
     * @return static|null
     */
    public static function find(int $id): ?static
    {
        return static::where('id', $id)->first();
    }

    /**
     * @return null
     */
    public static function all() : null | array
    {
        return static::get();
    }

    public static function paginate(int $perPage = 15): ?Paginator
    {
        $page       = (int) request('page', 1);
        $perPage    = (int) request('per_page', $perPage);
        $offset     = ($page - 1) * $perPage;
        $collection = static::get([], $perPage, $offset);
        $total      = static::count();
        return new Paginator(data: $collection, total: $total, currentPage: $page, perPage: $perPage);
    }

    /**
     * @return int
     */
    public static function count(): int
    {
        $query = "SELECT COUNT(*) as count FROM " . static::getTable();

        if (static::$conditions) {
            $conditions = array_map(fn($condition) => "{$condition['column']} {$condition['operator']} ?", static::$conditions);
            $query .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $prepare = parent::$db->prepare($query);
        $prepare->execute(static::getConditionValues());
        $data = $prepare->fetch(static::getDBconf()->FETCH_MODE);

        return $data->count ?? 0;
    }

    // In first() method:
    public static function first(): ?static
    {
        static::initializeDB();
        $query   = static::buildSelectQuery();
        $prepare = parent::$db->prepare($query);
        $prepare->execute(static::getConditionValues());
        $data = $prepare->fetch(static::getDBconf()->FETCH_MODE);
        static::resetConditions(); // Reset here
        if ($data) {
            $instance = new static();
            $instance->setAttributes((array) $data);
            return $instance;
        }
        return null;
    }

    public static function get(null | array $columns = [], ?int $limit = null, ?int $offset = null): ?Collection
    {
        static::initializeDB();
        $query   = static::buildSelectQuery($columns, $limit, $offset);
        $prepare = parent::$db->prepare($query);
        $prepare->execute(static::getConditionValues());
        $data = $prepare->fetchAll(static::getDBconf()->FETCH_MODE);
        static::resetConditions(); // Reset here
        return $data ? new Collection($data) : null;
    }

}
