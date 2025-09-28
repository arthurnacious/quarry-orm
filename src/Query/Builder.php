<?php

namespace Quarry\Query;

use Quarry\Quarry;
use Quarry\Entity\Collection;
use Quarry\Entity\Entity;
use PDO;

class Builder
{
    protected string $table;
    protected string $entityClass;
    protected string $connection;

    protected array $wheres = [];
    protected array $bindings = [];
    protected array $columns = ['*'];
    protected ?int $limit = null;
    protected array $orders = [];

    public function __construct(string $table, string $entityClass = 'stdClass', string $connection = null)
    {
        $this->table = $table;
        $this->entityClass = $entityClass;
        $this->connection = $connection ?? Quarry::getConfig()['default'];
    }

    public function where(string $column, string $operator, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = compact('column', 'operator', 'value');
        $this->bindings[] = $value;

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orders[] = compact('column', 'direction');
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function get(): Collection
    {
        $sql = $this->toSql();
        $pdo = Quarry::connection($this->connection)->getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($this->bindings);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($this->entityClass === 'stdClass') {
            $entities = array_map(fn($data) => (object) $data, $results);
        } else {
            $entities = array_map(fn($data) => new $this->entityClass($data), $results);
        }

        return new Collection($entities);
    }

    public function first(): ?Entity
    {
        $results = $this->limit(1)->get();
        return $results->first();
    }

    public function toSql(): string
    {
        $columns = $this->columns === ['*'] ? '*' : '`' . implode('`, `', $this->columns) . '`';
        $sql = "SELECT {$columns} FROM `{$this->table}`";

        if (!empty($this->wheres)) {
            $whereClauses = array_map(fn($where) => "`{$where['column']}` {$where['operator']} ?", $this->wheres);
            $sql .= " WHERE " . implode(' AND ', $whereClauses);
        }

        if (!empty($this->orders)) {
            $orderClauses = array_map(fn($order) => "`{$order['column']}` {$order['direction']}", $this->orders);
            $sql .= " ORDER BY " . implode(', ', $orderClauses);
        }

        if ($this->limit !== null) {
            $sql .= " LIMIT " . $this->limit;
        }

        return $sql;
    }

    public function count(): int
    {
        $clone = clone $this;
        $clone->columns = ['COUNT(*) as count'];

        $result = $clone->first();
        return $result ? (int) $result->count : 0;
    }
}
