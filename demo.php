<?php

require_once 'vendor/autoload.php';

use Quarry\Quarry;
use Quarry\Database\DB;
use Quarry\Model;

// Configure Quarry
Quarry::initialize([
    'pools' => [
        'primary' => [
            'max_connections' => 5,
            'max_idle_connections' => 3,
            'idle_timeout' => 30,
            'connection_config' => [
                'database_url' => 'sqlite:///demo.sqlite'
            ]
        ]
    ],
    'default_pool' => 'primary'
]);

// Create a simple model
class User extends Model
{
    protected static array $schema = [
        'id' => 'id|primary|autoincrement',
        'name' => 'varchar|max:100',
        'email' => 'varchar|max:255|unique',
        'active' => 'boolean|default:true',
        'created_at' => 'datetime|nullable',
    ];
}

// Test it
try {
    echo "🎉 Quarry ORM Demo\n";
    echo "==================\n\n";
    
    // Show table inference
    echo "📊 Table name inference:\n";
    echo "  User → " . User::getTable() . "\n";
    echo "  Pool: " . User::getPoolName() . "\n\n";
    
    // Show pool stats
    $pools = Quarry::getPools();
    foreach ($pools as $name => $pool) {
        $stats = $pool->getStats();
        echo "📈 Pool '{$name}':\n";
        echo "  Connections: {$stats['current_connections']} current, {$stats['idle_connections']} idle\n";
        echo "  Limits: {$stats['max_connections']} max, {$stats['max_idle_connections']} max idle\n\n";
    }
    
    // Test DB query builder
    echo "🔧 Testing Query Builder:\n";
    
    // Create table using raw SQL (for demo)
    $pool = Quarry::getPool('primary');
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
    
    // Insert using DB facade
    $userId = DB::table('users')->insert([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'active' => true
    ]);
    echo "  ✅ Inserted user with ID: {$userId}\n";
    
    // Query using DB facade
    $users = DB::table('users')->where('active', true)->get();
    echo "  ✅ Found " . count($users) . " active users\n";
    
    // Use Model
    $user = User::find($userId);
    if ($user) {
        echo "  ✅ Found user via Model: {$user->name} ({$user->email})\n";
    }
    
    // Update using DB facade
    $affected = DB::table('users')
        ->where('id', $userId)
        ->update(['name' => 'John Updated']);
    echo "  ✅ Updated {$affected} user(s)\n";
    
    // Count
    $count = DB::table('users')->count();
    echo "  ✅ Total users: {$count}\n";
    
    echo "\n🎊 Demo completed successfully!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}