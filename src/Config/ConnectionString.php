<?php

namespace Quarry\Config;

use InvalidArgumentException;

class ConnectionString
{
    public function __construct(
        public readonly string $driver,
        public readonly string $host,
        public readonly int $port,
        public readonly string $database,
        public readonly string $username = '',
        public readonly string $password = '',
        public readonly array $options = []
    ) {}

    public static function fromString(string $url): self
    {
        $parsed = parse_url($url);

        if ($parsed === false) {
            throw new InvalidArgumentException("Invalid database URL: {$url}");
        }

        $driver = $parsed['scheme'] ?? 'mysql';
        $host = $parsed['host'] ?? 'localhost';
        $port = $parsed['port'] ?? self::getDefaultPort($driver);
        $database = ltrim($parsed['path'] ?? '', '/');
        $username = $parsed['user'] ?? '';
        $password = $parsed['pass'] ?? '';

        return new self($driver, $host, $port, $database, $username, $password);
    }

    public function __toString(): string
    {
        $auth = $this->username ? "{$this->username}:{$this->password}@" : '';
        $port = $this->port ? ":{$this->port}" : '';
        return "{$this->driver}://{$auth}{$this->host}{$port}/{$this->database}";
    }

    private static function getDefaultPort(string $driver): int
    {
        return match ($driver) {
            'pgsql' => 5432,
            'mysql' => 3306,
            'sqlite' => 0,
            default => 0
        };
    }

    public function getDsn(): string
    {
        return match ($this->driver) {
            'sqlite' => "sqlite:{$this->database}",
            'mysql' => "mysql:host={$this->host};port={$this->port};dbname={$this->database};charset=utf8mb4",
            'pgsql' => "pgsql:host={$this->host};port={$this->port};dbname={$this->database}",
            default => throw new InvalidArgumentException("Unsupported driver: {$this->driver}")
        };
    }
}
