<?php

namespace Quarry\Config;

use InvalidArgumentException;

class DatabaseConfig
{
    /**
     * @param array<string, ConnectionConfig> $connections
     */
    public function __construct(
        public readonly array $connections,
        public readonly string $defaultConnection = 'primary'
    ) {
        $this->validate();
    }

    public static function fromArray(array $config): self
    {
        $connections = [];

        foreach ($config['connections'] as $name => $connectionConfig) {
            $connections[$name] = ConnectionConfig::fromArray($connectionConfig);
        }

        return new self(
            connections: $connections,
            defaultConnection: $config['default_connection'] ?? 'primary'
        );
    }

    public function toArray(): array
    {
        $connections = [];
        foreach ($this->connections as $name => $connection) {
            $connections[$name] = $connection->toArray();
        }

        return [
            'connections' => $connections,
            'default_connection' => $this->defaultConnection
        ];
    }

    private function validate(): void
    {
        if (empty($this->connections)) {
            throw new InvalidArgumentException('At least one connection must be configured');
        }

        if (!isset($this->connections[$this->defaultConnection])) {
            throw new InvalidArgumentException(
                "Default connection '{$this->defaultConnection}' not found in connections"
            );
        }
    }

    public function getConnection(string $name): ConnectionConfig
    {
        if (!isset($this->connections[$name])) {
            throw new InvalidArgumentException("Connection '{$name}' not found");
        }

        return $this->connections[$name];
    }
}
