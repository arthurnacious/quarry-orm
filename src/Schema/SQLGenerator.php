<?php

namespace Quarry\Schema;

use Quarry\Database\ConnectionFactory;

class SQLGenerator
{
    private \PDO $connection;

    public function __construct(\PDO $connection)
    {
        $this->connection = $connection;
    }

    public function generateCreateTable(Table $table): string
    {
        $driver = $this->connection->getAttribute(\PDO::ATTR_DRIVER_NAME);

        $columns = [];
        $foreignKeys = [];
        $primaryKeys = [];

        foreach ($table->columns as $column) {
            $columnSql = $this->generateColumnDefinition($column, $driver);
            $columns[] = $columnSql;

            // Only track primary keys that aren't already defined in column definition
            if ($column->primary && !$this->isPrimaryKeyInColumnDefinition($columnSql, $driver)) {
                $primaryKeys[] = $column->name;
            }

            if ($column->foreign) {
                $foreignKeys[] = $this->generateForeignKey($column);
            }
        }

        $sql = "CREATE TABLE IF NOT EXISTS {$table->name} (\n";
        $sql .= "    " . implode(",\n    ", $columns);

        // Add primary key constraint ONLY if not already defined in columns
        if (!empty($primaryKeys)) {
            $sql .= ",\n    PRIMARY KEY (" . implode(', ', $primaryKeys) . ")";
        }

        // Add foreign key constraints
        if (!empty($foreignKeys)) {
            $sql .= ",\n    " . implode(",\n    ", $foreignKeys);
        }

        $sql .= "\n)";

        // Add MySQL engine
        if ($driver === 'mysql') {
            $sql .= " ENGINE={$table->engine}";
        }

        $sql .= ";\n\n";

        // Add indexes (but skip duplicate unique indexes)
        $sql .= $this->generateIndexes($table, $driver);

        return $sql;
    }

    private function generateColumnDefinition(Column $column, string $driver): string
    {
        $definition = $column->name . ' ' . $this->getSQLType($column, $driver);

        $isPrimaryInDefinition = $this->isPrimaryKeyInColumnDefinition($this->getSQLType($column, $driver), $driver);

        if ($column->unique && !$column->primary && !$isPrimaryInDefinition) {
            $definition .= ' UNIQUE';
        }

        if (!$column->nullable && !$isPrimaryInDefinition) {
            $definition .= ' NOT NULL';
        }

        if ($column->default !== null && !$isPrimaryInDefinition) {
            $default = $this->formatDefaultValue($column->default, $driver);
            $definition .= " DEFAULT {$default}";
        }

        return $definition;
    }

    private function isPrimaryKeyInColumnDefinition(string $columnDefinition, string $driver): bool
    {
        return str_contains($columnDefinition, 'PRIMARY KEY');
    }

    private function getSQLType(Column $column, string $driver): string
    {
        return match ($column->type) {
            'id' => $this->getIdType($column, $driver),
            'varchar', 'string' => $this->getStringType($column),
            'char' => "CHAR({$column->size})",
            'text' => 'TEXT',
            'longtext' => 'LONGTEXT',
            'integer', 'int' => 'INTEGER',
            'boolean', 'bool' => 'BOOLEAN',
            'decimal' => "DECIMAL({$column->precision}, {$column->scale})",
            'float', 'double' => $this->getFloatType($column),
            'datetime', 'timestamp' => 'DATETIME',
            'date' => 'DATE',
            'time' => 'TIME',
            'json' => 'JSON',
            'enum' => $this->getEnumType($column, $driver),
            'set' => $this->getSetType($column, $driver),
            'binary', 'varbinary', 'blob' => $this->getBinaryType($column),
            default => strtoupper($column->type)
        };
    }

    private function getIdType(Column $column, string $driver): string
    {
        return match ($driver) {
            'pgsql' => $column->autoIncrement ? 'SERIAL' : 'INTEGER',
            'mysql' => $column->autoIncrement ? 'INTEGER AUTO_INCREMENT' : 'INTEGER',
            'sqlite' => $column->autoIncrement ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INTEGER',
            default => 'INTEGER'
        };
    }

    private function getStringType(Column $column): string
    {
        $max = $column->size ?? 255;

        if ($max <= 255) {
            return "VARCHAR({$max})";
        }

        return 'TEXT';
    }

    private function getEnumType(Column $column, string $driver): string
    {
        if ($driver === 'mysql' && !empty($column->enumValues)) {
            $values = array_map(fn($v) => "'{$v}'", $column->enumValues);
            return 'ENUM(' . implode(', ', $values) . ')';
        }

        // PostgreSQL and SQLite don't support ENUM, use VARCHAR with check constraint
        $maxLength = max(array_map('strlen', $column->enumValues ?? []));
        return "VARCHAR({$maxLength})";
    }

    private function getSetType(Column $column, string $driver): string
    {
        if ($driver === 'mysql' && !empty($column->enumValues)) {
            $values = array_map(fn($v) => "'{$v}'", $column->enumValues);
            return 'SET(' . implode(', ', $values) . ')';
        }

        return 'VARCHAR(255)';
    }

    private function getFloatType(Column $column): string
    {
        if ($column->precision && $column->scale) {
            return "FLOAT({$column->precision}, {$column->scale})";
        }

        return 'FLOAT';
    }

    private function getBinaryType(Column $column): string
    {
        $max = $column->size ?? 65535;

        if ($max <= 255) {
            return 'BLOB';
        } elseif ($max <= 65535) {
            return 'BLOB';
        } elseif ($max <= 16777215) {
            return 'MEDIUMBLOB';
        } else {
            return 'LONGBLOB';
        }
    }

    private function formatDefaultValue($value, string $driver): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if (in_array(strtoupper($value), ['CURRENT_TIMESTAMP', 'NOW()', 'CURRENT_DATE'])) {
            return strtoupper($value);
        }

        return "'{$value}'";
    }

    private function generateForeignKey(Column $column): string
    {
        $sql = "FOREIGN KEY ({$column->name}) REFERENCES {$column->foreign}";

        if ($column->onDelete) {
            $sql .= " ON DELETE " . strtoupper($column->onDelete);
        }

        if ($column->onUpdate) {
            $sql .= " ON UPDATE " . strtoupper($column->onUpdate);
        }

        return $sql;
    }

    private function generateIndexes(Table $table, string $driver): string
    {
        $sql = '';

        // Add regular indexes
        foreach ($table->indexes as $indexName => $columns) {
            if (is_array($columns)) {
                $columnList = implode(', ', $columns);
                $sql .= "CREATE INDEX IF NOT EXISTS {$indexName} ON {$table->name}({$columnList});\n";
            }
        }

        // Create unique indexes for unique columns, but skip if:
        // - It's a primary key (already unique)
        // - The column already has UNIQUE constraint in definition
        // - For SQLite, skip if it's a single-column unique that's already in column definition
        foreach ($table->columns as $column) {
            if ($column->unique && !$column->primary) {
                $columnDefinition = $this->generateColumnDefinition($column, $driver);

                // Skip if UNIQUE is already in column definition
                if (!str_contains($columnDefinition, 'UNIQUE')) {
                    $indexName = "idx_{$table->name}_{$column->name}_unique";
                    $sql .= "CREATE UNIQUE INDEX IF NOT EXISTS {$indexName} ON {$table->name}({$column->name});\n";
                }
            }
        }

        return $sql;
    }
}
