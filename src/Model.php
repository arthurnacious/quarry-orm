<?php

namespace Quarry;

use ReflectionClass;
use PDO;

abstract class Model
{
    protected static ?string $table = null;
    protected static string $connection;

    protected array $attributes = [];
    protected array $original = [];
    protected bool $exists = false;

    /**
     * Get the table name for this model
     */
    public static function getTable(): string
    {
        if (static::$table !== null) {
            return static::$table;
        }

        return static::inferTableName();
    }

    protected static function inferTableName(): string
    {
        $reflection = new ReflectionClass(static::class);
        $className = $reflection->getShortName();

        // Handle anonymous classes (for testing)
        if (str_contains($className, '@anonymous')) {
            return 'test_models';
        }

        return static::pluralize(
            static::camelCaseToSnakeCase($className)
        );
    }

    protected static function camelCaseToSnakeCase(string $input): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $input));
    }

    protected static function pluralize(string $word): string
    {
        $irregular = [
            'child' => 'children',
            'person' => 'people',
            'man' => 'men',
            'woman' => 'women',
            'foot' => 'feet',
            'tooth' => 'teeth',
            'mouse' => 'mice',
        ];

        if (isset($irregular[strtolower($word)])) {
            return $irregular[strtolower($word)];
        }

        if (substr($word, -1) === 'y') {
            return substr($word, 0, -1) . 'ies';
        }

        if (substr($word, -1) === 's' || substr($word, -2) === 'sh' || substr($word, -2) === 'ch') {
            return $word . 'es';
        }

        return $word . 's';
    }

    public static function setTable(string $table): void
    {
        static::$table = $table;
    }

    public static function getConnectionName(): string
    {
        return static::$connection ?? Quarry::getDefaultConnection();
    }

    public static function connection(string $connectionName): static
    {
        $model = new static();
        $model::$connection = $connectionName;
        return $model;
    }

    public static function all(): array
    {
        return static::executeWithConnection(function (PDO $connection) {
            $table = static::getTable();
            $stmt = $connection->query("SELECT * FROM {$table}");
            $results = [];

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $model = new static();
                $model->fill($row);
                $model->exists = true;
                $model->original = $row;
                $results[] = $model;
            }

            return $results;
        });
    }

    public static function find(int $id): ?static
    {
        return static::executeWithConnection(function (PDO $connection) use ($id) {
            $table = static::getTable();
            $stmt = $connection->prepare("SELECT * FROM {$table} WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $model = new static();
                $model->fill($row);
                $model->exists = true;
                $model->original = $row;
                return $model;
            }

            return null;
        });
    }

    protected static function executeWithConnection(callable $callback)
    {
        $connectionName = static::getConnectionName();
        $pool = Quarry::getConnectionPool($connectionName);

        $pdo = $pool->getConnection();
        try {
            return $callback($pdo);
        } finally {
            $pool->releaseConnection($pdo);
        }
    }

    public function save(): bool
    {
        return static::executeWithConnection(function (PDO $connection) {
            $table = static::getTable();

            if ($this->exists) {
                $updates = [];
                $values = [];

                foreach ($this->attributes as $key => $value) {
                    if ($key !== 'id' && $value !== ($this->original[$key] ?? null)) {
                        $updates[] = "{$key} = ?";
                        $values[] = $value;
                    }
                }

                if (empty($updates)) {
                    return true;
                }

                $values[] = $this->id;
                $sql = "UPDATE {$table} SET " . implode(', ', $updates) . " WHERE id = ?";
                $stmt = $connection->prepare($sql);
                $result = $stmt->execute($values);
            } else {
                // Insert
                $columns = array_keys($this->attributes);
                $placeholders = str_repeat('?,', count($columns) - 1) . '?';
                $values = array_values($this->attributes);

                $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES ({$placeholders})";
                $stmt = $connection->prepare($sql);
                $result = $stmt->execute($values);

                if ($result) {
                    $this->id = (int) $connection->lastInsertId();
                    $this->exists = true;
                    $this->original = $this->attributes;
                }
            }

            return $result;
        });
    }

    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        return static::executeWithConnection(function (PDO $connection) {
            $table = static::getTable();
            $stmt = $connection->prepare("DELETE FROM {$table} WHERE id = ?");
            $result = $stmt->execute([$this->id]);

            if ($result) {
                $this->exists = false;
                $this->attributes = [];
                $this->original = [];
            }

            return $result;
        });
    }

    public function __get(string $name)
    {
        return $this->attributes[$name] ?? null;
    }

    public function __set(string $name, $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    protected function fill(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }
    }

    public function toArray(): array
    {
        return $this->attributes;
    }
}
