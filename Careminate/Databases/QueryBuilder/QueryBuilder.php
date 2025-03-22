<?php 

namespace Careminate\Databases;

use PDO;
use Careminate\Databases\Connection;

class QueryBuilder
{
    private array $bindings = [];
    private array $wheres = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private string $table;

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    public function select(array $columns = ['*']): self
    {
        $this->columns = $columns;
        return $this;
    }

    public function where(string $column, string $operator, $value): self
    {
        $this->wheres[] = compact('column', 'operator', 'value');
        $this->bindings[":$column"] = $value;
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function get(): array
    {
        $sql = "SELECT " . implode(', ', $this->columns) . " FROM {$this->table}";
        $sql .= $this->buildWhereClause();
        $sql .= $this->buildLimitOffset();

        $stmt = Connection::getInstance()->prepare($sql);
        $stmt->execute($this->bindings);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Add other methods (first(), count(), etc) following similar pattern
}

