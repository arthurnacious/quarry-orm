<?php

namespace Quarry;

use Quarry\Database\PoolInterface;
use Quarry\Database\SyncPool;
use InvalidArgumentException;
use Quarry\Database\PoolFactory;

class Quarry
{
    private static array $connections = [];
    private static string $defaultConnection = 'primary';

    public static function registerConnection(string $name, PoolInterface $pool): void
    {
        self::$connections[$name] = $pool;
    }

    public static function getConnectionPool(string $name): PoolInterface
    {
        if (!isset(self::$connections[$name])) {
            throw new InvalidArgumentException("Connection pool '{$name}' not found");
        }
        return self::$connections[$name];
    }
    public static function getConnection(string $name): PoolInterface
    {
        return self::getConnectionPool($name);
    }

    public static function setDefaultConnection(string $name): void
    {
        if (!isset(self::$connections[$name])) {
            throw new InvalidArgumentException("Cannot set default connection: '{$name}' not found");
        }
        self::$defaultConnection = $name;
    }

    public static function getDefaultConnection(): string
    {
        return self::$defaultConnection;
    }

    public static function getConnections(): array
    {
        return self::$connections;
    }

    public static function hasConnection(string $name): bool
    {
        return isset(self::$connections[$name]);
    }

    public static function initialize(array|\Quarry\Config\DatabaseConfig $config): void
    {
        if ($config instanceof \Quarry\Config\DatabaseConfig) {
            $config = $config->toArray();
        }

        foreach ($config['connections'] as $connectionName => $connectionConfig) {
            $pool = PoolFactory::create($connectionConfig);
            self::registerConnection($connectionName, $pool);
        }

        if (isset($config['default_connection'])) {
            self::setDefaultConnection($config['default_connection']);
        }
    }

    public static function closeAll(): void
    {
        foreach (self::$connections as $connection) {
            $connection->close();
        }
        self::$connections = [];
    }
}
