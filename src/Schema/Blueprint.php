<?php

namespace Quarry\Schema;

class Blueprint
{
    private string $tableName;
    private array $columns = [];
    private array $primaryKeys = [];
    private array $foreignKeys = [];
    private array $indexes = [];

    public function __construct(string $tableName)
    {
        $this->tableName = $tableName;
    }

    public function addColumn(string $name, string $definition): void
    {
        $this->columns[$name] = $definition;

        if (str_contains($definition, 'primary')) {
            $this->primaryKeys[] = $name;
        }

        if (str_contains($definition, 'foreign:')) {
            preg_match('/foreign:([\w\.]+)/', $definition, $matches);
            if ($matches) {
                $this->foreignKeys[] = [
                    'column' => $name,
                    'reference' => $matches[1]
                ];
            }
        }

        if (str_contains($definition, 'index')) {
            $this->indexes[] = $name;
        }
    }

    public function toSql(): string
    {
        $columnDefs = [];

        foreach ($this->columns as $name => $definition) {
            $columnDefs[] = $this->buildColumnSql($name, $definition);
        }

        // Primary keys
        if (!empty($this->primaryKeys)) {
            $columnDefs[] = "PRIMARY KEY (`" . implode('`, `', $this->primaryKeys) . "`)";
        }

        // Indexes
        foreach ($this->indexes as $indexColumn) {
            $columnDefs[] = "INDEX `idx_{$indexColumn}` (`{$indexColumn}`)";
        }

        // Foreign keys
        foreach ($this->foreignKeys as $fk) {
            $columnDefs[] = "FOREIGN KEY (`{$fk['column']}`) REFERENCES {$fk['reference']}";
        }

        return sprintf(
            "CREATE TABLE `%s` (\n    %s\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            $this->tableName,
            implode(",\n    ", $columnDefs)
        );
    }

    private function buildColumnSql(string $name, string $definition): string
    {
        $parts = explode('|', $definition);
        $type = array_shift($parts);

        $sql = "`{$name}` " . $this->mapType($type, $parts);

        foreach ($parts as $modifier) {
            $sql .= $this->applyModifier($modifier, $type);
        }

        // Default to NOT NULL unless specified as nullable
        if (!in_array('nullable', $parts)) {
            $sql .= ' NOT NULL';
        }

        return $sql;
    }

    private function mapType(string $type, array $modifiers): string
    {
        $size = $this->extractSize($modifiers);

        return match ($type) {
            'id' => 'INT UNSIGNED AUTO_INCREMENT',
            'integer', 'int' => 'INT' . $this->getIntegerSize($size),
            'bigint' => 'BIGINT' . $this->getIntegerSize($size),
            'smallint' => 'SMALLINT' . $this->getIntegerSize($size),
            'tinyint' => 'TINYINT' . $this->getIntegerSize($size),

            // String types with character support
            'string', 'varchar' => 'VARCHAR' . $this->getStringSize($size, 255),
            'char', 'character' => 'CHAR' . $this->getStringSize($size, 1),
            'text' => 'TEXT' . $this->getTextSize($size),
            'mediumtext' => 'MEDIUMTEXT',
            'longtext' => 'LONGTEXT',

            // Binary types
            'binary' => 'BINARY' . $this->getStringSize($size, 1),
            'varbinary' => 'VARBINARY' . $this->getStringSize($size, 255),
            'blob' => 'BLOB' . $this->getTextSize($size),
            'mediumblob' => 'MEDIUMBLOB',
            'longblob' => 'LONGBLOB',

            // Boolean
            'boolean', 'bool' => 'TINYINT(1)',

            // Date/Time
            'datetime' => 'DATETIME',
            'timestamp' => 'TIMESTAMP',
            'date' => 'DATE',
            'time' => 'TIME',
            'year' => 'YEAR',

            // Numeric
            'float' => 'FLOAT',
            'double' => 'DOUBLE',
            'decimal' => 'DECIMAL' . $this->getDecimalSize($size),

            // JSON
            'json' => 'JSON',

            // Enum and Set (for specific character values)
            'enum' => $this->buildEnumType($modifiers),
            'set' => $this->buildSetType($modifiers),

            default => 'VARCHAR(255)'
        };
    }

    private function applyModifier(string $modifier, string $type): string
    {
        return match (true) {
            $modifier === 'primary' => ' PRIMARY KEY',
            $modifier === 'unique' => ' UNIQUE',
            $modifier === 'nullable' => '',
            $modifier === 'unsigned' => ' UNSIGNED',
            $modifier === 'autoincrement' => ' AUTO_INCREMENT',
            str_starts_with($modifier, 'default:') => $this->buildDefault($modifier, $type),
            str_starts_with($modifier, 'charset:') => $this->buildCharset($modifier),
            str_starts_with($modifier, 'collate:') => $this->buildCollate($modifier),
            str_starts_with($modifier, 'comment:') => $this->buildComment($modifier),
            default => ''
        };
    }

    private function extractSize(array $modifiers): ?int
    {
        foreach ($modifiers as $modifier) {
            if (preg_match('/max:(\d+)/', $modifier, $matches)) {
                return (int) $matches[1];
            }
            if (preg_match('/size:(\d+)/', $modifier, $matches)) {
                return (int) $matches[1];
            }
            if (preg_match('/length:(\d+)/', $modifier, $matches)) {
                return (int) $matches[1];
            }
        }
        return null;
    }

    private function getStringSize(?int $size, int $default): string
    {
        if ($size !== null) {
            return "({$size})";
        }
        return "({$default})";
    }

    private function getIntegerSize(?int $size): string
    {
        if ($size !== null) {
            return "({$size})";
        }
        return '';
    }

    private function getTextSize(?int $size): string
    {
        return $size !== null ? "({$size})" : '';
    }

    private function getDecimalSize(?int $size): string
    {
        if ($size !== null) {
            $precision = min($size, 65);
            $scale = max(0, min(30, $size / 2));
            return "({$precision},{$scale})";
        }
        return '(10,2)';
    }

    private function buildEnumType(array $modifiers): string
    {
        foreach ($modifiers as $modifier) {
            if (preg_match('/values:([\w,]+)/', $modifier, $matches)) {
                $values = explode(',', $matches[1]);
                $quotedValues = array_map(fn($v) => "'{$v}'", $values);
                return 'ENUM(' . implode(',', $quotedValues) . ')';
            }
        }
        return "ENUM('')"; // Default empty enum
    }

    private function buildSetType(array $modifiers): string
    {
        foreach ($modifiers as $modifier) {
            if (preg_match('/values:([\w,]+)/', $modifier, $matches)) {
                $values = explode(',', $matches[1]);
                $quotedValues = array_map(fn($v) => "'{$v}'", $values);
                return 'SET(' . implode(',', $quotedValues) . ')';
            }
        }
        return "SET('')"; // Default empty set
    }

    private function buildDefault(string $modifier, string $type): string
    {
        preg_match('/default:([\w\'\s]+)/', $modifier, $matches);
        if (!$matches) return '';

        $defaultValue = $matches[1];

        // Add quotes for string types, enum, set, date, etc.
        $needsQuotes = in_array($type, ['string', 'varchar', 'char', 'text', 'datetime', 'timestamp', 'date', 'time', 'enum', 'set']);

        if ($needsQuotes && !in_array(strtoupper($defaultValue), ['CURRENT_TIMESTAMP', 'NOW()'])) {
            // Check if it's already quoted
            if (!preg_match('/^\'.*\'$/', $defaultValue)) {
                $defaultValue = "'{$defaultValue}'";
            }
        }

        return " DEFAULT {$defaultValue}";
    }

    private function buildCharset(string $modifier): string
    {
        preg_match('/charset:(\w+)/', $modifier, $matches);
        return $matches ? " CHARACTER SET {$matches[1]}" : '';
    }

    private function buildCollate(string $modifier): string
    {
        preg_match('/collate:(\w+)/', $modifier, $matches);
        return $matches ? " COLLATE {$matches[1]}" : '';
    }

    private function buildComment(string $modifier): string
    {
        preg_match('/comment:\'([^\']+)\'/', $modifier, $matches);
        return $matches ? " COMMENT '{$matches[1]}'" : '';
    }
}
