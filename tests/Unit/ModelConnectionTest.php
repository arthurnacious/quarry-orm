<?php

namespace Quarry\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quarry\Quarry;
use Quarry\Database\RoadstarPool;

class ModelConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        Quarry::registerConnection('test', new RoadstarPool([
            'connection_config' => ['database_url' => 'sqlite::memory:']
        ]));

        // Create test table
        $pool = Quarry::getConnectionPool('test');
        $scope = new \Quarry\Database\ConnectionScope($pool);
        $connection = $scope->getConnection();
        $connection->exec('
CREATE TABLE users (
id INTEGER PRIMARY KEY AUTOINCREMENT,
name VARCHAR(100),
email VARCHAR(255)
)
');
        $scope->release();
    }

    protected function tearDown(): void
    {
        Quarry::closeAll();
    }

    public function test_model_find_uses_connection_pool(): void
    {
        // Insert test data
        $pool = Quarry::getConnectionPool('test');
        $scope = new \Quarry\Database\ConnectionScope($pool);
        $connection = $scope->getConnection();
        $connection->exec("INSERT INTO users (name, email) VALUES ('John', 'john@test.com')");
        $scope->release();

        $user = CnnectionTestUser::find(1);

        // Debug the exists property access
        // var_dump('exists via __get:', $user->exists);
        // var_dump('exists via property_exists:', property_exists($user, 'exists'));
        // var_dump('exists direct access (should fail):', $user->exists); // This will fail but show the error

        $this->assertNotNull($user);
        $this->assertEquals('John', $user->name);
        $this->assertEquals('john@test.com', $user->email);
        $this->assertTrue($user->exists);
    }

    public function test_model_all_uses_connection_pool(): void
    {
        // Insert multiple records
        $pool = Quarry::getConnectionPool('test');
        $scope = new \Quarry\Database\ConnectionScope($pool);
        $connection = $scope->getConnection();
        $connection->exec("INSERT INTO users (name, email) VALUES ('User1', 'user1@test.com')");
        $connection->exec("INSERT INTO users (name, email) VALUES ('User2', 'user2@test.com')");
        $scope->release();

        $users = CnnectionTestUser::all();

        $this->assertCount(2, $users);
        $this->assertEquals('User1', $users[0]->name);
        $this->assertEquals('User2', $users[1]->name);
    }

    public function test_model_all_works(): void
    {
        $pool = Quarry::getConnectionPool('test');
        $scope = new \Quarry\Database\ConnectionScope($pool);
        $connection = $scope->getConnection();
        $connection->exec("INSERT INTO users (name, email) VALUES ('User1', 'user1@test.com')");
        $connection->exec("INSERT INTO users (name, email) VALUES ('User2', 'user2@test.com')");
        $scope->release();

        $users = CnnectionTestUser::all();

        $this->assertCount(2, $users);
        $this->assertEquals('User1', $users[0]->name);
        $this->assertEquals('User2', $users[1]->name);
        $this->assertTrue($users[0]->exists);
        $this->assertTrue($users[1]->exists);
    }

    public function test_model_save_insert(): void
    {
        $user = new CnnectionTestUser();
        $user->name = 'New User';
        $user->email = 'new@test.com';

        $result = $user->save();

        $this->assertTrue($result);
        $this->assertTrue($user->exists);
        $this->assertEquals(1, $user->id);
    }

    public function test_model_save_update(): void
    {
        // First insert
        $user = new CnnectionTestUser();
        $user->name = 'Original';
        $user->email = 'original@test.com';
        $user->save();

        // Then update
        $user->name = 'Updated';
        $result = $user->save();

        $this->assertTrue($result);

        // Verify update
        $updatedUser = CnnectionTestUser::find(1);
        $this->assertEquals('Updated', $updatedUser->name);
    }
}

class CnnectionTestUser extends \Quarry\Model
{
    protected static ?string $table = 'users';
    protected static string $connection = 'test';
}
