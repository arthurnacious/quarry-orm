<?php

namespace Quarry\Schema;

use Quarry\Connection;
use PDO;

class Manager
{
    private array $schema;
    private PDO $db;

    public function __construct(array $schema)
    {
        $this->schema = $schema;
        $this->db = Connection::get();
    }

    public function createTables(): void
    {
        foreach ($this->schema as $tableName => $columns) {
            $this->createTable($tableName, $columns);
        }
    }

    public function dropTables(): void
    {
        foreach (array_reverse($this->schema) as $tableName => $columns) {
            if ($this->tableExists($tableName)) {
                $this->db->exec("DROP TABLE `{$tableName}`");
            }
        }
    }

    public function tableExists(string $tableName): bool
    {
        $stmt = $this->db->query("SHOW TABLES LIKE '{$tableName}'");
        return $stmt->rowCount() > 0;
    }

    private function createTable(string $tableName, array $columns): void
    {
        if ($this->tableExists($tableName)) {
            return;
        }

        $blueprint = new Blueprint($tableName);

        foreach ($columns as $columnName => $definition) {
            $blueprint->addColumn($columnName, $definition);
        }

        $sql = $blueprint->toSql();
        $this->db->exec($sql);
    }
}
