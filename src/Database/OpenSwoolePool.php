<?php

namespace Quarry\Database;

use PDO;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;

class OpenSwoolePool extends AbstractPool
{
    private Channel $pool;
    private int $currentConnections = 0;
    private array $connectionTimestamps = [];
    private bool $inCoroutine = false;

    public function __construct(array $config)
    {
        if (!extension_loaded('openswoole')) {
            throw new \RuntimeException('OpenSwoole extension is required for OpenSwoolePool');
        }

        parent::__construct($config);

        $this->inCoroutine = Coroutine::getCid() > -1;
        $this->pool = new Channel($this->maxPoolSize);

        if ($this->inCoroutine) {
            $this->preheatPool();
        }
    }

    private function preheatPool(): void
    {
        if (!$this->inCoroutine) {
            return; // Only preheat in coroutine context
        }

        $initialConnections = min(2, $this->maxIdle);
        for ($i = 0; $i < $initialConnections; $i++) {
            $connection = $this->createConnection();
            $this->pool->push($connection);
            $this->connectionTimestamps[spl_object_id($connection)] = microtime(true);
            $this->currentConnections++;
        }
    }

    public function getConnection(): PDO
    {
        if (!$this->inCoroutine) {
            // Fallback to simple array-based pool when not in coroutine
            return $this->getConnectionSync();
        }

        // Try to get connection from pool with timeout
        $connection = $this->pool->pop(0.5); // 500ms timeout

        if ($connection === false) {
            // Timeout or empty pool
            if ($this->currentConnections < $this->maxPoolSize) {
                $connection = $this->createConnection();
                $this->currentConnections++;
            } else {
                throw new \RuntimeException('No available connections in OpenSwoole pool (timeout)');
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

    /**
     * Fallback implementation for non-coroutine contexts (like tests)
     */
    private function getConnectionSync(): PDO
    {
        static $syncPool = [];
        static $syncConnections = 0;

        if (!empty($syncPool)) {
            $connection = array_shift($syncPool);
            if ($this->validateConnection($connection)) {
                return $connection;
            }
            $syncConnections--;
        }

        if ($syncConnections < $this->maxPoolSize) {
            $syncConnections++;
            return $this->createConnection();
        }

        throw new \RuntimeException('No available connections in OpenSwoole pool (sync mode)');
    }

    public function releaseConnection(PDO $connection): void
    {
        $this->resetConnection($connection);

        if (!$this->validateConnection($connection)) {
            unset($connection);
            $this->currentConnections--;
            unset($this->connectionTimestamps[spl_object_id($connection)]);
            return;
        }

        if (!$this->inCoroutine) {
            // Fallback to simple array-based pool when not in coroutine
            $this->releaseConnectionSync($connection);
            return;
        }

        // Clean up old connections before releasing
        $this->cleanupIdleConnections();

        if ($this->pool->length() < $this->maxIdle) {
            $this->pool->push($connection);
            $this->connectionTimestamps[spl_object_id($connection)] = microtime(true);
        } else {
            // Pool is full, close the connection
            unset($connection);
            $this->currentConnections--;
            unset($this->connectionTimestamps[spl_object_id($connection)]);
        }
    }

    /**
     * Fallback implementation for non-coroutine contexts
     */
    private function releaseConnectionSync(PDO $connection): void
    {
        static $syncPool = [];
        static $syncConnections = 0;

        if (count($syncPool) < $this->maxIdle) {
            $syncPool[] = $connection;
        } else {
            unset($connection);
            $syncConnections--;
        }
    }

    private function cleanupIdleConnections(): void
    {
        if (!$this->inCoroutine) {
            return;
        }

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
        if ($this->inCoroutine) {
            $this->cleanupIdleConnections();
        }

        return [
            'driver' => 'openswoole',
            'current_connections' => $this->currentConnections,
            'idle_connections' => $this->inCoroutine ? $this->pool->length() : 0,
            'max_pool_size' => $this->maxPoolSize,
            'max_idle' => $this->maxIdle,
            'idle_timeout' => $this->idleTimeout,
            'uptime' => microtime(true) - $this->createdAt,
            'in_coroutine' => $this->inCoroutine,
        ];
    }

    public function close(): void
    {
        if ($this->inCoroutine) {
            while (!$this->pool->isEmpty()) {
                $connection = $this->pool->pop(0.1);
                if ($connection !== false) {
                    unset($connection);
                }
            }
            $this->pool->close();
        }

        $this->currentConnections = 0;
        $this->connectionTimestamps = [];
    }

    public function isAsync(): bool
    {
        return true;
    }
}
