<?php

return [
    'pools' => [
        'primary' => [
            'max_connections' => 5,
            'max_idle_connections' => 3,
            'idle_timeout' => 30,
            'connection_config' => [
                'database_url' => 'sqlite:///database.sqlite'
            ]
        ],
        'read' => [
            'max_connections' => 10,
            'max_idle_connections' => 5,
            'idle_timeout' => 60,
            'connection_config' => [
                'database_url' => 'sqlite:///database.sqlite' // Same for demo
            ]
        ]
    ],
    
    'default_pool' => 'primary',
    
    'migrations' => [
        'path' => __DIR__ . '/../database/migrations',
        'table' => 'quarry_migrations'
    ]
];