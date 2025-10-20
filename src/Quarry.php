<?php

namespace Quarry;

use Quarry\Database\PoolInterface;
use Quarry\Database\SyncPool;
use InvalidArgumentException;
use RuntimeException;

class Quarry
{
    private static array $pools = [];
    private static string $defaultPool = 'primary';

    public static function registerPool(string $name, PoolInterface $pool): void
    {
        self::$pools[$name] = $pool;
    }

    public static function getPool(string $name): PoolInterface
    {
        if (!isset(self::$pools[$name])) {
            throw new InvalidArgumentException("Pool '{$name}' not found");
        }
        
        return self::$pools[$name];
    }

    public static function setDefaultPool(string $name): void
    {
        if (!isset(self::$pools[$name])) {
            throw new InvalidArgumentException("Cannot set default pool: '{$name}' not found");
        }
        
        self::$defaultPool = $name;
    }

    public static function getDefaultPool(): string
    {
        return self::$defaultPool;
    }

    public static function getPools(): array
    {
        return self::$pools;
    }

    public static function hasPool(string $name): bool
    {
        return isset(self::$pools[$name]);
    }

    public static function initialize(array $config): void
    {
        foreach ($config['pools'] as $poolName => $poolConfig) {
            $pool = new SyncPool($poolConfig);
            self::registerPool($poolName, $pool);
        }

        if (isset($config['default_pool'])) {
            self::setDefaultPool($config['default_pool']);
        }
    }

    public static function closeAll(): void
    {
        foreach (self::$pools as $pool) {
            $pool->close();
        }
        self::$pools = [];
    }
}