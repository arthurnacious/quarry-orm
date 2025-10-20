<?php

namespace Quarry\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quarry\Quarry;
use Quarry\Database\DB;
use Quarry\Database\SyncPool;

class QueryBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        // Use file-based SQLite to persist across connections
        $testDbPath = __DIR__ . '/test_database.sqlite';
        
        // Register test pool
        Quarry::registerPool('test', new SyncPool([
            'max_connections' => 5,
            'connection_config' => [
                'database_url' => 'sqlite:///' . $testDbPath
            ]
        ]));

        // Create test table
        $pool = Quarry::getPool('test');
        $connection = $pool->getConnection();
        $connection->exec('
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(100),
                email VARCHAR(255),
                active BOOLEAN DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $pool->releaseConnection($connection);
    }

    protected function tearDown(): void
    {
        Quarry::closeAll();
        
        // Clean up test database file
        $testDbPath = __DIR__ . '/test_database.sqlite';
        if (file_exists($testDbPath)) {
            unlink($testDbPath);
        }
    }

    public function test_basic_select(): void
    {
        $results = DB::table('users', 'test')->get();
        $this->assertIsArray($results);
    }

    public function test_where_conditions(): void
    {
        $query = DB::table('users', 'test')
            ->where('active', 1)
            ->where('name', 'like', 'John%');
        
        $this->assertInstanceOf(DB::class, $query);
    }

    public function test_insert_operation(): void
    {
        $id = DB::table('users', 'test')->insert([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'active' => 1
        ]);
        
        $this->assertIsInt($id);
        $this->assertEquals(1, $id);
    }

    public function test_count_operation(): void
    {
        // Insert test data
        DB::table('users', 'test')->insert([
            'name' => 'Test User',
            'email' => 'test@example.com'
        ]);
        
        $count = DB::table('users', 'test')->count();
        $this->assertEquals(1, $count);
    }

    public function test_first_operation(): void
    {
        // Insert test data
        DB::table('users', 'test')->insert([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com'
        ]);
        
        $user = DB::table('users', 'test')->first();
        $this->assertIsArray($user);
        $this->assertEquals('Jane Doe', $user['name']);
    }

    public function test_select_specific_columns(): void
    {
        DB::table('users', 'test')->insert([
            'name' => 'John Doe',
            'email' => 'john@example.com'
        ]);
        
        $users = DB::table('users', 'test')
            ->select('id', 'name')
            ->get();
        
        $this->assertCount(1, $users);
        $this->assertArrayHasKey('id', $users[0]);
        $this->assertArrayHasKey('name', $users[0]);
        $this->assertArrayNotHasKey('email', $users[0]);
    }

    public function test_order_by(): void
    {
        DB::table('users', 'test')->insert(['name' => 'Alpha', 'email' => 'alpha@example.com']);
        DB::table('users', 'test')->insert(['name' => 'Beta', 'email' => 'beta@example.com']);
        
        $users = DB::table('users', 'test')
            ->orderBy('name', 'DESC')
            ->get();
        
        $this->assertCount(2, $users);
        $this->assertEquals('Beta', $users[0]['name']);
    }

    public function test_limit_offset(): void
    {
        DB::table('users', 'test')->insert(['name' => 'User 1', 'email' => 'user1@example.com']);
        DB::table('users', 'test')->insert(['name' => 'User 2', 'email' => 'user2@example.com']);
        DB::table('users', 'test')->insert(['name' => 'User 3', 'email' => 'user3@example.com']);
        
        $users = DB::table('users', 'test')
            ->limit(2)
            ->offset(1)
            ->orderBy('id')
            ->get();
        
        $this->assertCount(2, $users);
        $this->assertEquals('User 2', $users[0]['name']);
    }

    public function test_update_operation(): void
    {
        $id = DB::table('users', 'test')->insert([
            'name' => 'Old Name',
            'email' => 'old@example.com'
        ]);
        
        $affected = DB::table('users', 'test')
            ->where('id', $id)
            ->update(['name' => 'New Name']);
        
        $this->assertEquals(1, $affected);
        
        $user = DB::table('users', 'test')->where('id', $id)->first();
        $this->assertEquals('New Name', $user['name']);
    }

    public function test_delete_operation(): void
    {
        $id = DB::table('users', 'test')->insert([
            'name' => 'To Delete',
            'email' => 'delete@example.com'
        ]);
        
        $deleted = DB::table('users', 'test')
            ->where('id', $id)
            ->delete();
        
        $this->assertEquals(1, $deleted);
        
        $count = DB::table('users', 'test')->where('id', $id)->count();
        $this->assertEquals(0, $count);
    }

    public function test_exists_operation(): void
    {
        $exists = DB::table('users', 'test')
            ->where('name', 'Non-existent')
            ->exists();
        
        $this->assertFalse($exists);
        
        DB::table('users', 'test')->insert([
            'name' => 'Existing User',
            'email' => 'existing@example.com'
        ]);
        
        $exists = DB::table('users', 'test')
            ->where('name', 'Existing User')
            ->exists();
        
        $this->assertTrue($exists);
    }

    public function test_or_where_conditions(): void
    {
        DB::table('users', 'test')->insert(['name' => 'John', 'email' => 'john@example.com']);
        DB::table('users', 'test')->insert(['name' => 'Jane', 'email' => 'jane@example.com']);
        
        $users = DB::table('users', 'test')
            ->where('name', 'John')
            ->orWhere('name', 'Jane')
            ->get();
        
        $this->assertCount(2, $users);
    }

    public function test_where_in_conditions(): void
    {
        DB::table('users', 'test')->insert(['name' => 'User1', 'email' => 'user1@example.com']);
        DB::table('users', 'test')->insert(['name' => 'User2', 'email' => 'user2@example.com']);
        DB::table('users', 'test')->insert(['name' => 'User3', 'email' => 'user3@example.com']);
        
        $users = DB::table('users', 'test')
            ->whereIn('name', ['User1', 'User2'])
            ->get();
        
        $this->assertCount(2, $users);
    }

    public function test_pool_method(): void
    {
        $query = DB::pool('test')->from('users');
        $this->assertInstanceOf(DB::class, $query);
        
        $results = $query->get();
        $this->assertIsArray($results);
    }
}