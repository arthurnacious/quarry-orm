<?php

use Quarry\Config\DatabaseConfig;
use Quarry\Config\ConnectionConfig;
use Quarry\Config\ConnectionString;

return new DatabaseConfig(
    connections: [
        'primary' => new ConnectionConfig(
            maxPoolSize: 5,
            maxIdle: 3,
            idleTimeout: 30,
            connectionConfig: ConnectionString::fromString('sqlite:///database.sqlite')
        ),
        'read' => new ConnectionConfig(
            maxPoolSize: 10,
            maxIdle: 5,
            idleTimeout: 60,
            connectionConfig: ConnectionString::fromString('pgsql://user:pass@read-replica:5432/db')
        )
    ],
    defaultConnection: 'primary'
);


// return DatabaseConfigBuilder::create()
//     ->addConnection('primary', new ConnectionConfig(
//         maxPoolSize: 5,
//         maxIdle: 3,
//         idleTimeout: 30,
//         connectionConfig: ConnectionString::fromString('sqlite:///database.sqlite')
//     ))
//     ->addConnection('read', new ConnectionConfig(
//         maxPoolSize: 10,
//         maxIdle: 5,
//         idleTimeout: 60,
//         connectionConfig: ConnectionString::fromString('pgsql://user:pass@read-replica:5432/db')
//     ))
//     ->setDefaultConnection('primary')
//     ->build();


// use Quarry\Config\DatabaseConfig;

// return DatabaseConfig::fromArray([
//     'connections' => [
//         'primary' => [
//             'max_pool_size' => 5,
//             'max_idle' => 3,
//             'idle_timeout' => 30,
//             'connection_config' => [
//                 'database_url' => 'sqlite:///database.sqlite'
//             ]
//         ]
//     ],
//     'default_connection' => 'primary'
// ]);