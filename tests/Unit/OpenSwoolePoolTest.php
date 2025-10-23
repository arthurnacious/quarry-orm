<?php

namespace Quarry\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quarry\Database\OpenSwoolePool;
use PDO;
use RuntimeException;

class OpenSwoolePoolTest extends TestCase
{
    public function test_openswoole_pool_requires_extension(): void
    {
        if (extension_loaded('openswoole')) {
            $this->markTestSkipped('OpenSwoole extension is available');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenSwoole extension is required');

        new OpenSwoolePool([
            'max_pool_size' => 3,
            'max_idle' => 2, // FIXED: Added max_idle
            'connection_config' => ['database_url' => 'sqlite::memory:']
        ]);
    }

    public function test_openswoole_pool_works_in_non_coroutine_context(): void
    {
        if (!extension_loaded('openswoole')) {
            $this->markTestSkipped('OpenSwoole extension not available');
        }

        $pool = new OpenSwoolePool([
            'max_pool_size' => 3,
            'max_idle' => 2, // FIXED
            'connection_config' => ['database_url' => 'sqlite::memory:']
        ]);

        // Should work even outside coroutine context
        $connection = $pool->getConnection();
        $this->assertInstanceOf(PDO::class, $connection);

        // Test the connection works
        $result = $connection->query('SELECT 1 as test');
        $data = $result->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals(1, $data['test']);

        $pool->releaseConnection($connection);
        $pool->close();
    }

    public function test_is_async_returns_true(): void
    {
        if (!extension_loaded('openswoole')) {
            $this->markTestSkipped('OpenSwoole extension not available');
        }

        $pool = new OpenSwoolePool([
            'max_pool_size' => 3,
            'max_idle' => 2, // FIXED
            'connection_config' => ['database_url' => 'sqlite::memory:']
        ]);

        $this->assertTrue($pool->isAsync());
        $pool->close();
    }

    public function test_stats_include_coroutine_context(): void
    {
        if (!extension_loaded('openswoole')) {
            $this->markTestSkipped('OpenSwoole extension not available');
        }

        $pool = new OpenSwoolePool([
            'max_pool_size' => 3,
            'max_idle' => 2, // FIXED
            'connection_config' => ['database_url' => 'sqlite::memory:']
        ]);

        $stats = $pool->getStats();

        $this->assertEquals('openswoole', $stats['driver']);
        $this->assertArrayHasKey('in_coroutine', $stats);
        $this->assertIsBool($stats['in_coroutine']);

        $pool->close();
    }
}
