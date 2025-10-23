<?php

return [
    'connections' => [
        'primary' => [
            'max_pool_size' => 5,
            'max_idle' => 3,
            'idle_timeout' => 30,
            'connection_config' => [
                'database_url' => 'sqlite:///database.sqlite'
            ]
        ],
        'read' => [
            'max_pool_size' => 10,
            'max_idle' => 5,
            'idle_timeout' => 60,
            'connection_config' => [
                'database_url' => 'sqlite:///database.sqlite'
            ]
        ]
    ],
    'default_connection' => 'primary'
];
