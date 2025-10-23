<?php

namespace Quarry\Database;

use PDO;
use SplQueue;

class RoadstarPool extends AbstractPool
{
    private SplQueue $pool;
    private int $currentConnections = 0;

    public function __construct(array $config)
    {
        parent::__construct($config);

        $this->pool = new SplQueue();
        $this->preheatPool();
    }

    private function preheatPool(): void
    {
        $initialConnections = min(2, $this->maxIdle);
        for ($i = 0; $i < $initialConnections; $i++) {
            $this->pool->push($this->createConnection());
            $this->currentConnections++;
        }
        $this->currentConnections = $initialConnections;
    }

    public function getConnection(): PDO
    {
        if (!$this->pool->isEmpty()) {
            $connection = $this->pool->shift();
            if ($this->validateConnection($connection)) {
                return $connection;
            }
            $this->currentConnections--;
        }

        if ($this->currentConnections < $this->maxPoolSize) {
            $this->currentConnections++;
            return $this->createConnection();
        }

        throw new \RuntimeException('No available connections in pool');
    }

    public function releaseConnection(PDO $connection): void
    {
        $this->resetConnection($connection);

        if ($this->validateConnection($connection)) {
            if ($this->pool->count() < $this->maxIdle) {
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

    public function getStats(): array
    {
        return [
            'driver' => 'roadstar',
            'current_connections' => $this->currentConnections,
            'idle_connections' => $this->pool->count(),
            'max_pool_size' => $this->maxPoolSize,
            'max_idle' => $this->maxIdle,
            'idle_timeout' => $this->idleTimeout,
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
