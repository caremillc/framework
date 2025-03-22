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
    private Connection $connection;

    public function __construct(string $table, Connection $connection)
    {
        $this->table = $table;
        $this->connection = $connection;
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

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($this->bindings);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function buildWhereClause(): string
    {
        // Build WHERE clause based on added conditions
        $where = [];
        foreach ($this->wheres as $whereCondition) {
            $where[] = "{$whereCondition['column']} {$whereCondition['operator']} :{$whereCondition['column']}";
        }

        return $where ? ' WHERE ' . implode(' AND ', $where) : '';
    }

    private function buildLimitOffset(): string
    {
        $limit = $this->limit ? " LIMIT {$this->limit}" : '';
        $offset = $this->offset ? " OFFSET {$this->offset}" : '';
        return $limit . $offset;
    }

    // Additional methods for other operations like first(), count(), etc.
}
