<?php

namespace Quarry\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quarry\Quarry;
use Quarry\Database\SyncPool;
use Quarry\Database\ConnectionScope;

class ConnectionLeakTest extends TestCase
{
    protected function setUp(): void
    {
        Quarry::registerConnection('leak_test', new SyncPool([
            'max_pool_size' => 2, // Small pool to easily detect leaks
            'max_idle' => 1,
            'idle_timeout' => 30,
            'connection_config' => [
                'database_url' => 'sqlite::memory:'
            ]
        ]));
    }

    protected function tearDown(): void
    {
        Quarry::closeAll();
    }

    public function test_connection_scope_releases_connections(): void
    {
        $pool = Quarry::getConnectionPool('leak_test'); // FIXED: getConnectionPool

        // Get initial stats
        $initialStats = $pool->getStats();
        $initialIdle = $initialStats['idle_connections'];

        // Use connection scope multiple times
        for ($i = 0; $i < 10; $i++) {
            $scope = new ConnectionScope($pool);
            $connection = $scope->getConnection();
            $connection->query('SELECT 1');
            // Scope automatically releases when it goes out of scope
        }

        // Should have same number of idle connections (no leaks)
        $finalStats = $pool->getStats();
        $this->assertEquals($initialIdle, $finalStats['idle_connections']);
    }

    public function test_manual_release_works(): void
    {
        $pool = Quarry::getConnectionPool('leak_test'); // FIXED: getConnectionPool
        $initialIdle = $pool->getStats()['idle_connections'];

        $scope = new ConnectionScope($pool);
        $connection = $scope->getConnection();
        $connection->query('SELECT 1');

        // Manually release
        $scope->release();

        // Should be back to initial state
        $finalIdle = $pool->getStats()['idle_connections'];
        $this->assertEquals($initialIdle, $finalIdle);
    }

    public function test_destructor_releases_connections(): void
    {
        $pool = Quarry::getConnectionPool('leak_test');
        $initialIdle = $pool->getStats()['idle_connections'];

        // Create scope in separate block to test destructor
        function createAndUseScope($pool)
        {
            $scope = new ConnectionScope($pool);
            $connection = $scope->getConnection();
            $connection->query('SELECT 1');
            // Scope destructor should release connection when function ends
        }

        createAndUseScope($pool);

        // Should be back to initial state after destructor
        $finalIdle = $pool->getStats()['idle_connections'];
        $this->assertEquals($initialIdle, $finalIdle);
    }
}
