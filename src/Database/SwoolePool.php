<?php

namespace Quarry\Database;

use PDO;
use Swoole\Coroutine\Channel;

class SwoolePool extends AbstractPool
{
    private Channel $pool;
    private int $currentConnections = 0;

    public function __construct(array $config)
    {
        if (!extension_loaded('swoole')) {
            throw new \RuntimeException('Swoole extension is required for SwoolePool');
        }

        parent::__construct($config);

        $this->pool = new Channel($this->maxPoolSize);
        $this->preheatPool();
    }

    public function isAsync(): bool
    {
        return true;
    }
}
