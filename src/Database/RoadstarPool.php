<?php

namespace Quarry\Database;

use PDO;

class RoadstarPool implements PoolInterface
{
    private array $config;
    private ?PDO $connection = null;
    private bool $inTransaction = false;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getConnection(): PDO
    {
        if ($this->connection && ConnectionFactory::validateConnection($this->connection)) {
            return $this->connection;
        }

        $this->connection = ConnectionFactory::create($this->config['connection_config']);
        return $this->connection;
    }

    public function releaseConnection(PDO $connection): void
    {
        // For Roadstar, we keep the connection open
        // Only reset if it's a different connection (shouldn't happen)
        if ($connection !== $this->connection) {
            $connection = null;
        }

        // Reset connection state if not in transaction
        if (!$this->inTransaction) {
            ConnectionFactory::resetConnection($connection);
        }
    }

    public function getStats(): array
    {
        return [
            'driver' => 'roadstar',
            'strategy' => 'single-connection',
            'has_connection' => $this->connection !== null,
            'in_transaction' => $this->inTransaction,
            'is_async' => false
        ];
    }

    public function close(): void
    {
        if ($this->connection) {
            $this->connection = null;
        }
    }

    public function isAsync(): bool
    {
        return false;
    }
}
