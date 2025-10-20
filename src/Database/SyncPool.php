<?php

namespace Quarry\Database;

use PDO;
use SplQueue;

class SyncPool implements PoolInterface
{
    private SplQueue $pool;
    private array $config;
    private int $maxConnections;
    private int $maxIdleConnections;
    private int $idleTimeout;
    private int $currentConnections = 0;
    private float $createdAt;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->maxConnections = $config['max_connections'] ?? 20;
        $this->maxIdleConnections = $config['max_idle_connections'] ?? 10;
        $this->idleTimeout = $config['idle_timeout'] ?? 30;
        $this->createdAt = microtime(true);

        $this->pool = new SplQueue();
        $this->preheatPool();
    }

    private function preheatPool(): void
    {
        $initialConnections = min(2, $this->maxIdleConnections);
        for ($i = 0; $i < $initialConnections; $i++) {
            $this->pool->push($this->createConnection());
        }
    }

    public function getConnection(): PDO
    {
        if (!$this->pool->isEmpty()) {
            $connection = $this->pool->shift();
            if (ConnectionFactory::validateConnection($connection)) {
                return $connection;
            }
            $this->currentConnections--;
        }

        if ($this->currentConnections < $this->maxConnections) {
            return $this->createConnection();
        }

        throw new \RuntimeException('No available connections in pool');
    }

    public function releaseConnection(PDO $connection): void
    {
        ConnectionFactory::resetConnection($connection);

        if (ConnectionFactory::validateConnection($connection)) {
            if ($this->pool->count() < $this->maxIdleConnections) {
                $this->pool->push($connection);
            } else {
                unset($connection);
                $this->currentConnections--;
            }
        } else {
            unset($connection);
            $this->currentConnections--;
        }
    }

    private function createConnection(): PDO
    {
        $this->currentConnections++;
        return ConnectionFactory::create($this->config['connection_config']);
    }

    public function getStats(): array
    {
        return [
            'driver' => 'sync',
            'current_connections' => $this->currentConnections,
            'idle_connections' => $this->pool->count(),
            'max_connections' => $this->maxConnections,
            'max_idle_connections' => $this->maxIdleConnections,
            'uptime' => microtime(true) - $this->createdAt,
        ];
    }

    public function close(): void
    {
        while (!$this->pool->isEmpty()) {
            $connection = $this->pool->shift();
            unset($connection);
        }
        $this->currentConnections = 0;
    }

    public function isAsync(): bool
    {
        return false;
    }
}