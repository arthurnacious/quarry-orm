<?php

namespace Quarry\Entity;

use Quarry\Quarry;
use Quarry\Query\Builder;
use Quarry\Support\Pluralizer;

abstract class Entity
{
    protected static ?string $table = null;
    protected static ?string $connection = null;
    protected static string $primaryKey = 'id';
    protected static bool $singularTable = false;

    protected array $attributes = [];
    protected array $hidden = [];
    protected array $fillable = [];
    protected bool $exists = false;

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    public static function getTable(): string
    {
        if (static::$table !== null) {
            return static::$table;
        }

        $className = basename(str_replace('\\', '/', static::class));
        $snakeCase = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className));

        return static::$singularTable ? $snakeCase : Pluralizer::pluralize($snakeCase);
    }

    public static function getConnection(): string
    {
        return static::$connection ?? Quarry::getConfig()['default'];
    }

    public static function query(): Builder
    {
        return new Builder(static::getTable(), static::class, static::getConnection());
    }

    public static function all(): Collection
    {
        return static::query()->get();
    }

    public static function find(int $id): ?static
    {
        return static::query()->where(static::$primaryKey, $id)->first();
    }

    public static function create(array $attributes): static
    {
        $entity = new static($attributes);
        $entity->save();
        return $entity;
    }

    public function save(): bool
    {
        return $this->exists ? $this->performUpdate() : $this->performInsert();
    }

    protected function performInsert(): bool
    {
        $data = $this->getAttributes();
        unset($data[static::$primaryKey]);

        $columns = array_keys($data);
        $placeholders = str_repeat('?,', count($columns) - 1) . '?';

        $sql = "INSERT INTO `" . static::getTable() . "` (`" . implode('`,`', $columns) . "`) 
                VALUES ({$placeholders})";

        $stmt = Quarry::connection(static::getConnection())->getPdo()->prepare($sql);
        $result = $stmt->execute(array_values($data));

        if ($result) {
            $this->attributes[static::$primaryKey] = Quarry::connection(static::getConnection())->getPdo()->lastInsertId();
            $this->exists = true;
        }

        return $result;
    }

    protected function performUpdate(): bool
    {
        $data = $this->getAttributes();
        $primaryValue = $data[static::$primaryKey];
        unset($data[static::$primaryKey]);

        $setClause = implode(', ', array_map(fn($col) => "`{$col}` = ?", array_keys($data)));

        $sql = "UPDATE `" . static::getTable() . "` SET {$setClause} 
                WHERE `" . static::$primaryKey . "` = ?";

        $values = array_merge(array_values($data), [$primaryValue]);
        $stmt = Quarry::connection(static::getConnection())->getPdo()->prepare($sql);

        return $stmt->execute($values);
    }

    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $sql = "DELETE FROM `" . static::getTable() . "` WHERE `" . static::$primaryKey . "` = ?";
        $stmt = Quarry::connection(static::getConnection())->getPdo()->prepare($sql);
        $result = $stmt->execute([$this->attributes[static::$primaryKey]]);

        if ($result) {
            $this->exists = false;
        }

        return $result;
    }

    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if (empty($this->fillable) || in_array($key, $this->fillable)) {
                $this->attributes[$key] = $value;
            }
        }
        return $this;
    }

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function __set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function toArray(): array
    {
        $data = $this->attributes;

        foreach ($this->hidden as $hidden) {
            unset($data[$hidden]);
        }

        return $data;
    }

    protected function getAttributes(): array
    {
        return $this->attributes;
    }
}
