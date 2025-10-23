<?php

namespace Quarry\Database;

use PDO;
use OpenSwoole\Coroutine\Channel;

class OpenSwoolePool extends AbstractPool
{
    private Channel $pool;
    private int $currentConnections = 0;
    private array $connectionTimestamps = [];

    public function __construct(array $config)
    {
        if (!extension_loaded('openswoole')) {
            throw new \RuntimeException('OpenSwoole extension is required for OpenSwoolePool');
        }

        parent::__construct($config);

        $this->pool = new Channel($this->maxPoolSize);
        $this->preheatPool();
    }

    private function preheatPool(): void
    {
        $initialConnections = min(2, $this->maxIdle);
        for ($i = 0; $i < $initialConnections; $i++) {
            $connection = $this->createConnection();
            $this->pool->push($connection);
            $this->connectionTimestamps[spl_object_id($connection)] = microtime(true);
        }
        $this->currentConnections = $initialConnections;
    }

    public function getConnection(): PDO
    {
        // Try to get connection from pool with timeout
        $connection = $this->pool->pop(0.5); // 500ms timeout

        if ($connection === false) {
            // Timeout or empty pool
            if ($this->currentConnections < $this->maxPoolSize) {
                $connection = $this->createConnection();
                $this->currentConnections++;
            } else {
                throw new \RuntimeException('No available connections in pool (timeout)');
            }
        } else {
            // Validate connection from pool
            if (!$this->validateConnection($connection)) {
                $this->currentConnections--;
                unset($this->connectionTimestamps[spl_object_id($connection)]);
                return $this->getConnection(); // Recursively try again
            }
        }

        $this->connectionTimestamps[spl_object_id($connection)] = microtime(true);
        return $connection;
    }

    public function releaseConnection(PDO $connection): void
    {
        $this->resetConnection($connection);

        if ($this->validateConnection($connection)) {
            // Clean up old connections before releasing
            $this->cleanupIdleConnections();

            if ($this->pool->length() < $this->maxIdle) {
                $this->pool->push($connection);
                $this->connectionTimestamps[spl_object_id($connection)] = microtime(true);
            } else {
                // Pool is full, close the connection
                $this->currentConnections--;
                unset($this->connectionTimestamps[spl_object_id($connection)]);
                unset($connection);
            }
        } else {
            $this->currentConnections--;
            unset($this->connectionTimestamps[spl_object_id($connection)]);
            unset($connection);
        }
    }

    private function cleanupIdleConnections(): void
    {
        $now = microtime(true);
        $length = $this->pool->length();

        // Temporary storage for valid connections
        $validConnections = [];

        for ($i = 0; $i < $length; $i++) {
            $conn = $this->pool->pop(0.1);
            if ($conn === false) break;

            $connId = spl_object_id($conn);
            $idleTime = $now - ($this->connectionTimestamps[$connId] ?? $now);

            if ($idleTime < $this->idleTimeout && $this->validateConnection($conn)) {
                $validConnections[] = $conn;
            } else {
                // Connection is stale, close it
                unset($conn);
                $this->currentConnections--;
                unset($this->connectionTimestamps[$connId]);
            }
        }

        // Push valid connections back to pool
        foreach ($validConnections as $conn) {
            $this->pool->push($conn);
        }
    }

    public function getStats(): array
    {
        $this->cleanupIdleConnections();

        return [
            'driver' => 'openswoole',
            'current_connections' => $this->currentConnections,
            'idle_connections' => $this->pool->length(),
            'max_pool_size' => $this->maxPoolSize,
            'max_idle' => $this->maxIdle,
            'idle_timeout' => $this->idleTimeout,
            'uptime' => microtime(true) - $this->createdAt,
            'channel_capacity' => $this->pool->capacity,
            'channel_length' => $this->pool->length(),
        ];
    }

    public function close(): void
    {
        while (!$this->pool->isEmpty()) {
            $connection = $this->pool->pop(0.1);
            if ($connection !== false) {
                unset($connection);
            }
        }
        $this->currentConnections = 0;
        $this->connectionTimestamps = [];
        $this->pool->close();
    }

    public function isAsync(): bool
    {
        return true;
    }
}
