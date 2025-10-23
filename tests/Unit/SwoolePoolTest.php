<?php

namespace Quarry\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quarry\Database\SwoolePool;
use PDO;
use RuntimeException;

class SwoolePoolTest extends TestCase
{
    public function test_swoole_pool_requires_extension(): void
    {
        if (extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is available');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Swoole extension is required');

        new SwoolePool([
            'max_pool_size' => 3,
            'connection_config' => ['database_url' => 'sqlite::memory:']
        ]);
    }

    public function test_swoole_pool_works_in_non_coroutine_context(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension not available');
        }

        $pool = new SwoolePool([
            'max_pool_size' => 3,
            'connection_config' => ['database_url' => 'sqlite::memory:']
        ]);

        $connection = $pool->getConnection();
        $this->assertInstanceOf(PDO::class, $connection);

        $pool->releaseConnection($connection);
        $pool->close();
    }

    public function test_is_async_returns_true(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension not available');
        }

        $pool = new SwoolePool([
            'max_pool_size' => 3,
            'connection_config' => ['database_url' => 'sqlite::memory:']
        ]);

        $this->assertTrue($pool->isAsync());
        $pool->close();
    }
}
