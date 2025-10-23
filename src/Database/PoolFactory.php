<?php

namespace Quarry\Database;

class PoolFactory
{
    public static function create(array $config): PoolInterface
    {
        $strategy = $config['pool_strategy'] ?? 'roadstar';

        return match ($strategy) {
            'roadstar' => new RoadstarPool($config),
            'openswoole' => new OpenSwoolePool($config),
            'swoole' => new SwoolePool($config),
            default => throw new \InvalidArgumentException("Unknown pool strategy: {$strategy}")
        };
    }
}
