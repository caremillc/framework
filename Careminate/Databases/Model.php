<?php
namespace Careminate\Databases;

use Careminate\Databases\QueryBuilder\DBCondition;
use Careminate\Databases\QueryBuilder\DBSelector;

class Model extends BaseModel
{
    use DBCondition, DBSelector;

    protected $fillable = [];
    protected $guarded  = [];
    protected $attributes = [];

    public function __construct()
    {
        parent::__construct();
    }

    public static function getTable()
    {
        $class = new static;
        return $class->table ?? strtolower((new \ReflectionClass(static::class))->getShortName()) . 's';
    }

    public function toArray()
    {
        return $this->attributes;
    }

    protected function isFillable($key)
    {
        if (in_array($key, $this->fillable)) {
            return true;
        }

        if ($this->guarded === ['*']) {
            return false;
        }

        return empty($this->fillable) && ! in_array($key, $this->guarded);
    }

    public function fill(array $attributes)
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->$key = $value;
            }
        }
        return $this;
    }

    public function save()
    {
        $attributes = $this->getFilteredAttributes();

        if (empty($attributes)) {
            return false;
        }

        if (! isset($this->attributes['id'])) {
            $this->insert();
        } else {
            $this->update();
        }
        return true;
    }

    protected function insert()
    {
        $attributes = $this->getFilteredAttributes();

        $columns      = implode(', ', array_keys($attributes));
        $placeholders = implode(', ', array_fill(0, count($attributes), '?'));

        $sql  = "INSERT INTO {$this->getTable()} ($columns) VALUES ($placeholders)";
        $stmt = self::$db->prepare($sql);
        $stmt->execute(array_values($attributes));

        $this->attributes['id'] = self::$db->lastInsertId();
    }

    protected function update()
    {
        $attributes = $this->getFilteredAttributes();
        $id         = $this->attributes['id'];
        unset($attributes['id']);

        $setClause = implode(', ', array_map(fn($key) => "$key = ?", array_keys($attributes)));
        $sql       = "UPDATE {$this->getTable()} SET $setClause WHERE id = ?";
        $params    = array_merge(array_values($attributes), [$id]);

        $stmt = self::$db->prepare($sql);
        $stmt->execute($params);
    }

    // private function getFilteredAttributes()
    // {
    //     $filtered = [];
    //     foreach ($this->attributes as $key => $value) {
    //         if ($this->isFillable($key)) {
    //             $filtered[$key] = $value;
    //         }
    //     }
    //     return $filtered;
    // }

    private function getFilteredAttributes()
    {
        $filtered = [];
        foreach ($this->attributes as $key => $value) {
            if ($this->isGuarded($key)) {
                continue;
            }

            $filtered[$key] = $value;
        }
        return $filtered;
    }

    protected function isGuarded($key)
    {
        if ($this->guarded === ['*']) {
            return true;
        }

        return in_array($key, $this->guarded);
    }

    public static function create(array $attributes)
    {
        $model = new static();
        $model->fill($attributes)->save();
        return $model;
    }

    public function updateModel(array $attributes = [])
    {
        if (! empty($attributes)) {
            $this->fill($attributes);
        }
        return $this->save();
    }

     /**
     * Get a list of all records.
     */
    public static function all()
    {
        $table = (new static())->getTable();
        $sql = "SELECT * FROM $table";
        $stmt = self::$db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

      /**
     * Find a specific record by ID.
     */
    public static function find($id)
    {
        $table = (new static())->getTable();
        $sql = "SELECT * FROM $table WHERE id = ?";
        $stmt = self::$db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

     /**
     * Create a new record and save it to the database.
     */
    public static function store(array $attributes)
    {
        $model = new static();
        return $model->fill($attributes)->save();
    }

     /**
     * Edit an existing record.
     */
    public function edit($id)
    {
        $record = self::find($id);
        if ($record) {
            $this->fill($record);
            return $this;
        }
        return null;
    }
      /**
     * Update an existing record.
     */
    public function updateRecord(array $attributes)
    {
        return $this->updateModel($attributes);
    }

    /**
     * Delete a record from the database.
     */
    public static function destroy($id)
    {
        $table = (new static())->getTable();
        $sql = "DELETE FROM $table WHERE id = ?";
        $stmt = self::$db->prepare($sql);
        return $stmt->execute([$id]);
    }


}
