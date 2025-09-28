<?php

namespace Quarry;

class Quarry
{
    private static array $config = [];
    private static bool $initialized = false;

    public static function init(array $config): void
    {
        self::$config = array_merge([
            'default' => 'default',
            'connections' => [],
            'schema_path' => 'database/schema.php',
            'auto_create_tables' => false,
        ], $config);

        Connection\ConnectionManager::init(self::$config);
        self::$initialized = true;

        if (self::$config['auto_create_tables'] && file_exists(self::$config['schema_path'])) {
            self::createTables();
        }
    }

    public static function createTables(): void
    {
        if (!file_exists(self::$config['schema_path'])) {
            throw new \RuntimeException('Schema file not found: ' . self::$config['schema_path']);
        }

        $schema = require self::$config['schema_path'];
        $manager = new Schema\Manager($schema);
        $manager->createTables();
    }

    public static function dropTables(): void
    {
        if (!file_exists(self::$config['schema_path'])) {
            throw new \RuntimeException('Schema file not found: ' . self::$config['schema_path']);
        }

        $schema = require self::$config['schema_path'];
        $manager = new Schema\Manager($schema);
        $manager->dropTables();
    }

    public static function connection(?string $name = null): Connection\DatabaseConnection
    {
        return Connection\ConnectionManager::connection($name);
    }

    public static function table(string $table, ?string $connection = null): Query\Builder
    {
        $connection = $connection ?? self::$config['default'];
        return new Query\Builder($table, $connection);
    }

    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    public static function getConfig(): array
    {
        return self::$config;
    }
}
