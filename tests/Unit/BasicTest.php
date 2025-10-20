<?php

namespace Quarry\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quarry\Quarry;
use Quarry\Database\SyncPool;

// Create concrete test models instead of anonymous classes
class TestUser extends \Quarry\Model {}
class TestCategory extends \Quarry\Model {}
class TestUserProfile extends \Quarry\Model {}

class BasicTest extends TestCase
{
    protected function tearDown(): void
    {
        Quarry::closeAll();
    }

    public function test_pool_registration(): void
    {
        $pool = new SyncPool([
            'max_connections' => 5,
            'connection_config' => [
                'database_url' => 'sqlite::memory:'
            ]
        ]);

        Quarry::registerPool('test', $pool);
        
        $this->assertTrue(Quarry::hasPool('test'));
        $this->assertInstanceOf(SyncPool::class, Quarry::getPool('test'));
        
        // Test we can get a connection
        $connection = $pool->getConnection();
        $this->assertInstanceOf(\PDO::class, $connection);
        $pool->releaseConnection($connection);
    }

    public function test_table_inference(): void
    {
        $this->assertEquals('test_users', TestUser::getTable());
        $this->assertEquals('test_categories', TestCategory::getTable());
        $this->assertEquals('test_user_profiles', TestUserProfile::getTable());
    }

    public function test_anonymous_class_fallback(): void
    {
        // Test that anonymous classes don't break
        $anonymous = new class extends \Quarry\Model {};
        $table = $anonymous::getTable();
        $this->assertIsString($table);
        $this->assertNotEmpty($table);
    }
}