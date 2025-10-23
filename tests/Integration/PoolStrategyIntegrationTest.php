<?php

namespace Quarry\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Quarry\Quarry;
use Quarry\Database\RoadstarPool;
use Quarry\Database\OpenSwoolePool;

class PoolStrategyIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Quarry::closeAll();
    }

    public function test_quarry_initializes_with_pool_strategies(): void
    {
        $config = [
            'connections' => [
                'primary' => [
                    'pool_strategy' => 'roadstar',
                    'max_pool_size' => 5,
                    'max_idle' => 3, // FIXED: max_idle <= max_pool_size
                    'connection_config' => [
                        'database_url' => 'sqlite::memory:'
                    ]
                ],
                'read' => [
                    'pool_strategy' => 'roadstar',
                    'max_pool_size' => 10,
                    'max_idle' => 5, // FIXED: max_idle <= max_pool_size
                    'connection_config' => [
                        'database_url' => 'sqlite::memory:'
                    ]
                ]
            ],
            'default_connection' => 'primary'
        ];

        Quarry::initialize($config);

        $primaryPool = Quarry::getConnectionPool('primary');
        $readPool = Quarry::getConnectionPool('read');

        $this->assertInstanceOf(RoadstarPool::class, $primaryPool);
        $this->assertInstanceOf(RoadstarPool::class, $readPool);
        $this->assertEquals('primary', Quarry::getDefaultConnection());
    }

    public function test_different_pool_strategies_can_coexist(): void
    {
        if (!extension_loaded('openswoole')) {
            $this->markTestSkipped('OpenSwoole extension not available');
        }

        $config = [
            'connections' => [
                'web' => [
                    'pool_strategy' => 'roadstar',
                    'max_pool_size' => 5,
                    'max_idle' => 3, // FIXED
                    'connection_config' => [
                        'database_url' => 'sqlite::memory:'
                    ]
                ],
                'workers' => [
                    'pool_strategy' => 'openswoole',
                    'max_pool_size' => 20,
                    'max_idle' => 10, // FIXED
                    'connection_config' => [
                        'database_url' => 'sqlite::memory:'
                    ]
                ]
            ]
        ];

        Quarry::initialize($config);

        $webPool = Quarry::getConnectionPool('web');
        $workersPool = Quarry::getConnectionPool('workers');

        $this->assertInstanceOf(RoadstarPool::class, $webPool);
        $this->assertInstanceOf(OpenSwoolePool::class, $workersPool);
        $this->assertFalse($webPool->isAsync());
        $this->assertTrue($workersPool->isAsync());
    }

    public function test_connections_can_be_used_after_initialization(): void
    {
        $config = [
            'connections' => [
                'primary' => [
                    'pool_strategy' => 'roadstar',
                    'max_pool_size' => 5,
                    'max_idle' => 3, // FIXED
                    'connection_config' => [
                        'database_url' => 'sqlite::memory:'
                    ]
                ]
            ]
        ];

        Quarry::initialize($config);

        $pool = Quarry::getConnectionPool('primary');
        $connection = $pool->getConnection();

        // Test the connection works
        $result = $connection->query('SELECT 1 as test_value');
        $data = $result->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals(1, $data['test_value']);

        $pool->releaseConnection($connection);
    }
}
