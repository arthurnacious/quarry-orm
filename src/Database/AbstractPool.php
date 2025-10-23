<?php

namespace Quarry\Database;

use PDO;

abstract class AbstractPool implements PoolInterface
{
    protected array $config;
    protected int $maxPoolSize;
    protected int $maxIdle;
    protected int $idleTimeout;
    protected float $createdAt;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->maxPoolSize = $config['max_pool_size'] ?? 20;
        $this->maxIdle = $config['max_idle'] ?? 10;
        $this->idleTimeout = $config['idle_timeout'] ?? 30;
        $this->createdAt = microtime(true);

        $this->validateConfig();
    }

    protected function validateConfig(): void
    {
        if ($this->maxPoolSize < 1) {
            throw new \InvalidArgumentException('max_pool_size must be at least 1');
        }
        if ($this->maxIdle > $this->maxPoolSize) {
            throw new \InvalidArgumentException('max_idle cannot be greater than max_pool_size');
        }
    }

    protected function createConnection(): PDO
    {
        return ConnectionFactory::create($this->config['connection_config']);
    }

    protected function validateConnection(PDO $connection): bool
    {
        return ConnectionFactory::validateConnection($connection);
    }

    protected function resetConnection(PDO $connection): void
    {
        ConnectionFactory::resetConnection($connection);
    }

    abstract public function getStats(): array;
    abstract public function isAsync(): bool;
}
