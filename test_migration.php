<?php

require_once 'vendor/autoload.php';

use Quarry\Quarry;
use Quarry\Database\DB;

// Initialize with the same configuration
Quarry::initialize([
    'pools' => [
        'primary' => [
            'max_connections' => 5,
            'connection_config' => [
                'database_url' => 'sqlite:///database.sqlite'
            ]
        ]
    ]
]);

try {
    echo "🧪 Testing Migration Results\n";
    echo "============================\n\n";
    
    // Count users
    $userCount = DB::table('users')->count();
    echo "✅ Users in database: {$userCount}\n";
    
    // Count posts  
    $postCount = DB::table('posts')->count();
    echo "✅ Posts in database: {$postCount}\n";
    
    // Get published posts
    $publishedPosts = DB::table('posts')
        ->where('published', 1)
        ->count();
    echo "✅ Published posts: {$publishedPosts}\n";
    
    // Show users with posts
    $usersWithPosts = DB::table('users')
        ->select('users.name', DB::raw('COUNT(posts.id) as post_count'))
        ->leftJoin('posts', 'users.id', '=', 'posts.author_id')
        ->groupBy('users.id', 'users.name')
        ->get();
    
    echo "\n📊 User Post Counts:\n";
    foreach ($usersWithPosts as $user) {
        echo "  {$user['name']}: {$user['post_count']} posts\n";
    }
    
    echo "\n🎉 Migration test successful!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}