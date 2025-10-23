<?php

namespace Quarry\Config;

use InvalidArgumentException;

class ConnectionConfig
{
    public function __construct(
        public readonly string $poolStrategy = 'roadstar',
        public readonly int $maxPoolSize = 20,
        public readonly int $maxIdle = 10,
        public readonly int $idleTimeout = 30,
        public readonly ConnectionString $connectionConfig
    ) {
        $this->validate();
    }

    public static function fromArray(array $config): self
    {
        return new self(
            poolStrategy: $config['pool_strategy'] ?? 'roadstar',
            maxPoolSize: $config['max_pool_size'] ?? 20,
            maxIdle: $config['max_idle'] ?? 10,
            idleTimeout: $config['idle_timeout'] ?? 30,
            connectionConfig: ConnectionString::fromString(
                $config['connection_config']['database_url'] ?? ''
            )
        );
    }

    public function toArray(): array
    {
        return [
            'pool_strategy' => $this->poolStrategy,
            'max_pool_size' => $this->maxPoolSize,
            'max_idle' => $this->maxIdle,
            'idle_timeout' => $this->idleTimeout,
            'connection_config' => [
                'database_url' => (string) $this->connectionConfig
            ]
        ];
    }

    private function validate(): void
    {
        if (!$this->poolStrategy) {
            throw new InvalidArgumentException('pool_strategy must be not be empty');
        }

        if ($this->maxPoolSize < 1) {
            throw new InvalidArgumentException('max_pool_size must be at least 1');
        }

        if ($this->maxIdle > $this->maxPoolSize) {
            throw new InvalidArgumentException('max_idle cannot be greater than max_pool_size');
        }

        if ($this->idleTimeout < 0) {
            throw new InvalidArgumentException('idle_timeout cannot be negative');
        }
    }
}
