<?php

namespace Quarry\Database;

use PDO;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

class SwoolePool extends AbstractPool
{
    private Channel $pool;
    private int $currentConnections = 0;
    private bool $inCoroutine = false;

    public function __construct(array $config)
    {
        if (!extension_loaded('swoole')) {
            throw new \RuntimeException('Swoole extension is required for SwoolePool');
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
            return;
        }

        $initialConnections = min(2, $this->maxIdle);
        for ($i = 0; $i < $initialConnections; $i++) {
            $connection = $this->createConnection();
            $this->pool->push($connection);
            $this->currentConnections++;
        }
    }

    public function getConnection(): PDO
    {
        if (!$this->inCoroutine) {
            // Fallback for non-coroutine context
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

            throw new \RuntimeException('No available connections in Swoole pool (sync mode)');
        }

        // Coroutine implementation...
        $connection = $this->pool->pop(0.5);

        if ($connection === false) {
            if ($this->currentConnections < $this->maxPoolSize) {
                $connection = $this->createConnection();
                $this->currentConnections++;
            } else {
                throw new \RuntimeException('No available connections in Swoole pool (timeout)');
            }
        }

        return $connection;
    }

    public function releaseConnection(PDO $connection): void
    {
        $this->resetConnection($connection);

        if (!$this->validateConnection($connection)) {
            unset($connection);
            $this->currentConnections--;
            return;
        }

        if (!$this->inCoroutine) {
            // Fallback for non-coroutine context
            static $syncPool = [];
            if (count($syncPool) < $this->maxIdle) {
                $syncPool[] = $connection;
            } else {
                unset($connection);
            }
            return;
        }

        if ($this->pool->length() < $this->maxIdle) {
            $this->pool->push($connection);
        } else {
            unset($connection);
            $this->currentConnections--;
        }
    }

    public function getStats(): array
    {
        $stats = [
            'driver' => 'swoole',
            'current_connections' => $this->currentConnections,
            'max_pool_size' => $this->maxPoolSize,
            'max_idle' => $this->maxIdle,
            'idle_timeout' => $this->idleTimeout,
            'uptime' => microtime(true) - $this->createdAt,
            'in_coroutine' => $this->inCoroutine,
        ];

        if ($this->inCoroutine) {
            $stats['idle_connections'] = $this->pool->length();
        } else {
            $stats['idle_connections'] = 0;
        }

        return $stats;
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
    }

    public function isAsync(): bool
    {
        return true;
    }
}
