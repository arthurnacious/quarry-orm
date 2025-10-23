<?php

namespace Quarry\Database;

use Quarry\Quarry;
use PDO;
use PDOStatement;

class DB
{
    private string $table;
    private string $connection;

    private array $query = [
        'select' => ['*'],
        'where' => [],
        'orderBy' => [],
        'limit' => null,
        'offset' => null,
        'joins' => [],
        'groupBy' => [],
    ];

    private array $bindings = [];

    public static function table(string $table, ?string $connection = null): self
    {
        $instance = new self();
        $instance->table = $table;

        // Use provided connection or try to get default, fallback to first available
        if ($connection) {
            $instance->connection = $connection;
        } elseif (Quarry::getConnections()) {
            $instance->connection = Quarry::getDefaultConnection();
        } else {
            throw new \RuntimeException('No database connections configured');
        }

        return $instance;
    }

    public static function connection(string $connection): self
    {
        $instance = new self();
        $instance->connection = $connection;
        return $instance;
    }

    // RAW expression - returns a RawExpression object
    public static function raw(string $value): RawExpression
    {
        return new RawExpression($value);
    }

    public function from(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    // SELECT operations - handle both strings and RawExpression objects
    public function select(...$columns): self
    {
        $this->query['select'] = $columns;
        return $this;
    }

    // LEFT JOIN operation
    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->query['joins'][] = [
            'type' => 'left',
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second
        ];
        return $this;
    }

    // GROUP BY operation
    public function groupBy(...$columns): self
    {
        $this->query['groupBy'] = $columns;
        return $this;
    }

    // WHERE operations
    public function where(string $column, string $operator, $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->query['where'][] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'and'
        ];

        $this->bindings[] = $value;
        return $this;
    }

    public function orWhere(string $column, string $operator, $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->query['where'][] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'or'
        ];

        $this->bindings[] = $value;
        return $this;
    }

    public function whereIn(string $column, array $values): self
    {
        $this->query['where'][] = [
            'type' => 'in',
            'column' => $column,
            'values' => $values,
            'boolean' => 'and'
        ];

        $this->bindings = array_merge($this->bindings, $values);
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->query['orderBy'][] = [
            'column' => $column,
            'direction' => strtoupper($direction)
        ];
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->query['limit'] = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->query['offset'] = $offset;
        return $this;
    }

    public function get(): array
    {
        return $this->executeWithPool(function (PDO $connection) {
            $sql = $this->buildSelectQuery();
            $stmt = $connection->prepare($sql);
            $stmt->execute($this->getBindings());

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        });
    }

    public function first(): ?array
    {
        $results = $this->limit(1)->get();
        return $results[0] ?? null;
    }

    public function count(): int
    {
        return $this->executeWithPool(function (PDO $connection) {
            $this->query['select'] = [DB::raw('COUNT(*) as count')];
            $sql = $this->buildSelectQuery();
            $stmt = $connection->prepare($sql);
            $stmt->execute($this->getBindings());

            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int) $result['count'];
        });
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function insert(array $data): int
    {
        return $this->executeWithPool(function (PDO $connection) use ($data) {
            $columns = array_keys($data);
            $values = array_values($data);
            $placeholders = str_repeat('?,', count($values) - 1) . '?';

            $sql = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") VALUES ({$placeholders})";
            $stmt = $connection->prepare($sql);
            $stmt->execute($values);

            return (int) $connection->lastInsertId();
        });
    }

    public function update(array $data): int
    {
        return $this->executeWithPool(function (PDO $connection) use ($data) {
            $set = [];
            $bindings = [];

            foreach ($data as $column => $value) {
                $set[] = "{$column} = ?";
                $bindings[] = $value;
            }

            $bindings = array_merge($bindings, $this->getBindings());
            $where = $this->buildWhere();

            $sql = "UPDATE {$this->table} SET " . implode(', ', $set) . $where;
            $stmt = $connection->prepare($sql);
            $stmt->execute($bindings);

            return $stmt->rowCount();
        });
    }

    public function delete(): int
    {
        return $this->executeWithPool(function (PDO $connection) {
            $where = $this->buildWhere();
            $bindings = $this->getBindings();

            $sql = "DELETE FROM {$this->table}" . $where;
            $stmt = $connection->prepare($sql);
            $stmt->execute($bindings);

            return $stmt->rowCount();
        });
    }

    private function buildSelectQuery(): string
    {
        $selectParts = [];
        foreach ($this->query['select'] as $column) {
            if ($column instanceof RawExpression) {
                // Handle raw expressions - include as-is
                $selectParts[] = $column->getValue();
            } else {
                $selectParts[] = $column;
            }
        }

        $select = implode(', ', $selectParts);
        $sql = "SELECT {$select} FROM {$this->table}";

        // Add LEFT JOINs
        if (!empty($this->query['joins'])) {
            foreach ($this->query['joins'] as $join) {
                $sql .= " LEFT JOIN {$join['table']} ON {$join['first']} {$join['operator']} {$join['second']}";
            }
        }

        $where = $this->buildWhere();
        if ($where) {
            $sql .= $where;
        }

        // Add GROUP BY
        if (!empty($this->query['groupBy'])) {
            $groupByParts = [];
            foreach ($this->query['groupBy'] as $column) {
                if ($column instanceof RawExpression) {
                    $groupByParts[] = $column->getValue();
                } else {
                    $groupByParts[] = $column;
                }
            }
            $sql .= " GROUP BY " . implode(', ', $groupByParts);
        }

        if (!empty($this->query['orderBy'])) {
            $orders = [];
            foreach ($this->query['orderBy'] as $order) {
                $orders[] = "{$order['column']} {$order['direction']}";
            }
            $sql .= " ORDER BY " . implode(', ', $orders);
        }

        if ($this->query['limit'] !== null) {
            $sql .= " LIMIT " . $this->query['limit'];
        }

        if ($this->query['offset'] !== null) {
            $sql .= " OFFSET " . $this->query['offset'];
        }

        return $sql;
    }

    private function buildWhere(): string
    {
        if (empty($this->query['where'])) {
            return '';
        }

        $where = ' WHERE ';
        $conditions = [];

        foreach ($this->query['where'] as $index => $whereClause) {
            if ($whereClause['type'] === 'in') {
                // Handle WHERE IN conditions
                $placeholders = str_repeat('?,', count($whereClause['values']) - 1) . '?';
                $condition = "{$whereClause['column']} IN ({$placeholders})";
            } else {
                // Handle basic WHERE conditions
                $condition = "{$whereClause['column']} {$whereClause['operator']} ?";
            }

            if ($index === 0) {
                $conditions[] = $condition;
            } else {
                $conditions[] = "{$whereClause['boolean']} {$condition}";
            }
        }

        return $where . implode(' ', $conditions);
    }

    private function getBindings(): array
    {
        return $this->bindings;
    }

    private function executeWithPool(callable $callback)
    {
        $pool = Quarry::getConnectionPool($this->connection);
        $connection = $pool->getConnection();

        try {
            return $callback($connection);
        } finally {
            $pool->releaseConnection($connection);
            $this->resetQuery();
        }
    }

    private function resetQuery(): void
    {
        $this->query = [
            'select' => ['*'],
            'where' => [],
            'orderBy' => [],
            'limit' => null,
            'offset' => null,
            'joins' => [],
            'groupBy' => [],
        ];
        $this->bindings = [];
    }
}

// RawExpression class to handle raw SQL expressions
class RawExpression
{
    private string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
