<?php

namespace Quarry\Database;

use Quarry\Quarry;
use PDO;
use PDOStatement;

class DB
{
    private string $table;
    private string $pool;
    
    private array $query = [
        'select' => ['*'],
        'where' => [],
        'orderBy' => [],
        'limit' => null,
        'offset' => null,
    ];
    
    private array $bindings = [];

        public static function table(string $table, string $pool = null): self
    {
        $instance = new self();
        $instance->table = $table;
        
        // Use provided pool or try to get default, fallback to first available
        if ($pool) {
            $instance->pool = $pool;
        } elseif (Quarry::getPools()) {
            $instance->pool = Quarry::getDefaultPool();
        } else {
            throw new \RuntimeException('No database pools configured');
        }
        
        return $instance;
    }

    public static function pool(string $pool): self
    {
        $instance = new self();
        $instance->pool = $pool;
        return $instance;
    }

    public function from(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    // SELECT operations
    public function select(string ...$columns): self
    {
        $this->query['select'] = $columns;
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
        return $this->executeWithPool(function(PDO $connection) {
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
        return $this->executeWithPool(function(PDO $connection) {
            $this->query['select'] = ['COUNT(*) as count'];
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
        return $this->executeWithPool(function(PDO $connection) use ($data) {
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
        return $this->executeWithPool(function(PDO $connection) use ($data) {
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
        return $this->executeWithPool(function(PDO $connection) {
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
        $select = implode(', ', $this->query['select']);
        $sql = "SELECT {$select} FROM {$this->table}";
        
        $where = $this->buildWhere();
        if ($where) {
            $sql .= $where;
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
        $pool = Quarry::getPool($this->pool);
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
        ];
        $this->bindings = [];
    }
}