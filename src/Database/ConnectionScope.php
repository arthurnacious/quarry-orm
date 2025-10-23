<?php
namespace Quarry\Database;

use PDO;

class ConnectionScope
{
    private PDO $connection;
    private PoolInterface $pool;
    private bool $released = false;

    public function __construct(PoolInterface $pool)
    {
        $this->pool = $pool;
        $this->connection = $pool->getConnection();
    }

    public function getConnection(): PDO
    {
        if ($this->released) {
            throw new \RuntimeException('Connection has already been released');
        }
        return $this->connection;
    }

    public function release(): void
    {
        if (!$this->released) {
            $this->pool->releaseConnection($this->connection);
            $this->released = true;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}