<?php

namespace Quarry\Config;

class DatabaseConfigBuilder
{
    private array $connections = [];
    private string $defaultConnection = 'primary';

    public function addConnection(string $name, ConnectionConfig $config): self
    {
        $this->connections[$name] = $config;
        return $this;
    }

    public function setDefaultConnection(string $name): self
    {
        $this->defaultConnection = $name;
        return $this;
    }

    public function build(): DatabaseConfig
    {
        return new DatabaseConfig($this->connections, $this->defaultConnection);
    }

    public static function create(): self
    {
        return new self();
    }
}
