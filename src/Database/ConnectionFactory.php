<?php

namespace Quarry\Database;

use PDO;
use PDOException;

class ConnectionFactory
{
    public static function create(array $config): PDO
    {
        $url = $config['database_url'];
        
        // Handle SQLite file paths
        if (str_starts_with($url, 'sqlite:///')) {
            $filePath = substr($url, 10);
            $dsn = "sqlite:{$filePath}";
            $username = '';
            $password = '';
        }
        // Handle SQLite memory
        else if ($url === 'sqlite:///:memory:') {
            $dsn = 'sqlite::memory:';
            $username = '';
            $password = '';
        }
        else {
            $parsed = parse_url($url);
            
            if ($parsed === false) {
                throw new PDOException("Invalid database URL: {$url}");
            }

            $driver = $parsed['scheme'] ?? 'pgsql';
            $host = $parsed['host'] ?? 'localhost';
            $port = $parsed['port'] ?? self::getDefaultPort($driver);
            $database = ltrim($parsed['path'] ?? '', '/');
            $username = $parsed['user'] ?? '';
            $password = $parsed['pass'] ?? '';

            $dsn = self::buildDSN($driver, $host, $port, $database);
        }

        $options = $config['options'] ?? [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT => false,
        ];

        try {
            $pdo = new PDO($dsn, $username, $password, $options);
            
            // Only initialize if we have a driver
            if (isset($driver)) {
                self::initializeConnection($pdo, $driver);
            }
            
            return $pdo;
        } catch (PDOException $e) {
            throw new PDOException("Failed to connect to database: " . $e->getMessage());
        }
    }

    private static function buildDSN(string $driver, string $host, int $port, string $database): string
    {
        return match ($driver) {
            'sqlite' => "sqlite:{$database}",
            'mysql' => "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            'pgsql' => "pgsql:host={$host};port={$port};dbname={$database}",
            default => throw new PDOException("Unsupported database driver: {$driver}")
        };
    }

    private static function getDefaultPort(string $driver): int
    {
        return match ($driver) {
            'pgsql' => 5432,
            'mysql' => 3306,
            default => 0
        };
    }

    private static function initializeConnection(PDO $pdo, string $driver): void
    {
        match ($driver) {
            'pgsql' => $pdo->exec("SET TIME ZONE 'UTC'"),
            'mysql' => $pdo->exec("SET time_zone = '+00:00'"),
            default => null
        };
    }

    public static function validateConnection(PDO $connection): bool
    {
        try {
            $connection->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    public static function resetConnection(PDO $connection): void
    {
        try {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        } catch (PDOException $e) {
            // lets sielntly swallow rollback errors
        }
    }
}