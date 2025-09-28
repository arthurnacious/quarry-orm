<?php

namespace Quarry\Connection;

use PDO;
use PDOException;
use RuntimeException;

class DatabaseConnection
{
    private ?PDO $pdo = null;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getPdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $driver = $this->config['driver'] ?? 'mysql';

        try {
            $this->pdo = match ($driver) {
                'mysql' => $this->createMysqlConnection(),
                'pgsql' => $this->createPgsqlConnection(),
                'sqlite' => $this->createSqliteConnection(),
                default => throw new RuntimeException("Unsupported driver: {$driver}")
            };

            return $this->pdo;
        } catch (PDOException $e) {
            throw new RuntimeException("Database connection failed: " . $e->getMessage());
        }
    }

    private function createMysqlConnection(): PDO
    {
        $dsn = sprintf(
            "mysql:host=%s;port=%d;dbname=%s;charset=%s",
            $this->config['host'] ?? 'localhost',
            $this->config['port'] ?? 3306,
            $this->config['database'] ?? '',
            $this->config['charset'] ?? 'utf8mb4'
        );

        return new PDO(
            $dsn,
            $this->config['username'] ?? '',
            $this->config['password'] ?? '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    private function createPgsqlConnection(): PDO
    {
        $dsn = sprintf(
            "pgsql:host=%s;port=%d;dbname=%s",
            $this->config['host'] ?? 'localhost',
            $this->config['port'] ?? 5432,
            $this->config['database'] ?? ''
        );

        return new PDO(
            $dsn,
            $this->config['username'] ?? '',
            $this->config['password'] ?? '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    private function createSqliteConnection(): PDO
    {
        $database = $this->config['database'] ?? ':memory:';
        return new PDO("sqlite:{$database}", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    public function getDriver(): string
    {
        return $this->config['driver'] ?? 'mysql';
    }
}
