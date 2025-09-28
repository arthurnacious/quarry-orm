<?php

namespace Quarry\Connection;

class ConnectionManager
{
    private static array $config = [];
    private static array $connections = [];

    public static function init(array $config): void
    {
        self::$config = $config;
    }

    public static function connection(?string $name = null): DatabaseConnection
    {
        $name = $name ?? self::$config['default'];

        if (!isset(self::$connections[$name])) {
            self::$connections[$name] = self::makeConnection($name);
        }

        return self::$connections[$name];
    }

    private static function makeConnection(string $name): DatabaseConnection
    {
        if (!isset(self::$config['connections'][$name])) {
            throw new \RuntimeException("Database connection [{$name}] not configured.");
        }

        $config = self::$config['connections'][$name];
        return new DatabaseConnection($config);
    }

    public static function disconnect(?string $name = null): void
    {
        $name = $name ?? self::$config['default'];
        unset(self::$connections[$name]);
    }
}
