<?php

namespace Quarry\Database;

use PDO;

interface PoolInterface
{
    public function getConnection(): PDO;
    public function releaseConnection(PDO $connection): void;
    public function getStats(): array;
    public function close(): void;
    public function isAsync(): bool;
}