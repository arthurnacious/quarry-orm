<?php

namespace Quarry\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quarry\Database\RoadstarPool;
use PDO;

class RoadstarPoolTest extends TestCase
{
    private RoadstarPool $pool;

    protected function setUp(): void
    {
        $this->pool = new RoadstarPool([
            'max_pool_size' => 3,
            'max_idle' => 2,
            'idle_timeout' => 30,
            'connection_config' => [
                'database_url' => 'sqlite::memory:'
            ]
        ]);
    }

    protected function tearDown(): void
    {
        $this->pool->close();
    }

    public function test_get_connection_returns_pdo_instance(): void
    {
        $connection = $this->pool->getConnection();

        $this->assertInstanceOf(PDO::class, $connection);
        $this->pool->releaseConnection($connection);
    }

    public function test_connection_can_execute_queries(): void
    {
        $connection = $this->pool->getConnection();

        $result = $connection->query('SELECT 1 as test');
        $data = $result->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals(1, $data['test']);
        $this->pool->releaseConnection($connection);
    }

    public function test_release_connection_returns_to_pool(): void
    {
        $initialStats = $this->pool->getStats();
        $initialIdle = $initialStats['idle_connections'];

        $connection = $this->pool->getConnection();
        $this->pool->releaseConnection($connection);

        $finalStats = $this->pool->getStats();

        $this->assertEquals($initialIdle, $finalStats['idle_connections']);
    }

    public function test_pool_respects_max_pool_size(): void
    {
        $connections = [];

        // Debug: Check initial state
        $initialStats = $this->pool->getStats();
        echo "Initial - Max: {$initialStats['max_pool_size']}, Current: {$initialStats['current_connections']}, Idle: {$initialStats['idle_connections']}\n";

        // Get maximum connections
        for ($i = 0; $i < 3; $i++) {
            $connections[] = $this->pool->getConnection();
            $stats = $this->pool->getStats();
            echo "After connection $i - Current: {$stats['current_connections']}, Idle: {$stats['idle_connections']}\n";
        }

        // Debug: Check state before trying to exceed limit
        $beforeExceptionStats = $this->pool->getStats();
        echo "Before exception - Current: {$beforeExceptionStats['current_connections']}, Idle: {$beforeExceptionStats['idle_connections']}\n";

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No available connections in pool');

        // This should fail
        try {
            $extraConnection = $this->pool->getConnection();
            echo "ERROR: Should have thrown exception but got connection!\n";
            $this->pool->releaseConnection($extraConnection); // Clean up if test fails
        } catch (\RuntimeException $e) {
            echo "SUCCESS: Exception thrown as expected: " . $e->getMessage() . "\n";
            throw $e; // Re-throw so PHPUnit can catch it
        }
    }

    public function test_release_connection_allows_new_connections(): void
    {
        $connections = [];

        // Get all connections
        for ($i = 0; $i < 3; $i++) {
            $connections[] = $this->pool->getConnection();
        }

        // Release one connection
        $this->pool->releaseConnection(array_pop($connections));

        // Should be able to get one more connection
        $newConnection = $this->pool->getConnection();
        $this->assertInstanceOf(PDO::class, $newConnection);

        $this->pool->releaseConnection($newConnection);
    }

    public function test_get_stats_returns_correct_information(): void
    {
        $stats = $this->pool->getStats();

        $this->assertEquals('roadstar', $stats['driver']);
        $this->assertEquals(3, $stats['max_pool_size']);
        $this->assertEquals(2, $stats['max_idle']);
        $this->assertEquals(30, $stats['idle_timeout']);
        $this->assertIsFloat($stats['uptime']);
        $this->assertIsInt($stats['current_connections']);
        $this->assertIsInt($stats['idle_connections']);
    }

    public function test_is_async_returns_false(): void
    {
        $this->assertFalse($this->pool->isAsync());
    }

    public function test_close_clears_all_connections(): void
    {
        $connection = $this->pool->getConnection();
        $this->pool->releaseConnection($connection);

        $statsBefore = $this->pool->getStats();
        $this->assertGreaterThan(0, $statsBefore['idle_connections']);

        $this->pool->close();

        $statsAfter = $this->pool->getStats();
        $this->assertEquals(0, $statsAfter['idle_connections']);
        $this->assertEquals(0, $statsAfter['current_connections']);
    }

    public function test_preheat_pool_creates_initial_connections(): void
    {
        $stats = $this->pool->getStats();

        // Should have preheated with min(2, max_idle) connections
        $this->assertEquals(2, $stats['idle_connections']);
        $this->assertEquals(2, $stats['current_connections']);
    }

    public function test_invalid_connection_is_removed_from_pool(): void
    {
        // Get and immediately release a connection to test pool behavior
        $connection = $this->pool->getConnection();
        $this->pool->releaseConnection($connection);

        $initialStats = $this->pool->getStats();
        $initialIdle = $initialStats['idle_connections'];

        // Simulate an invalid connection by closing it
        $connection = $this->pool->getConnection();
        $connection = null; // Simulate connection failure

        // The pool should handle this gracefully on next get
        $newConnection = $this->pool->getConnection();
        $this->assertInstanceOf(PDO::class, $newConnection);

        $this->pool->releaseConnection($newConnection);
    }
}
