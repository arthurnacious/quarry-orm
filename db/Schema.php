<?php

use Quarry\Schema\Table;
use Quarry\Schema\Column;

return [
    new Table(
        name: 'users',
        columns: [
            new Column('id', 'id', primary: true, autoIncrement: true),
            new Column('username', 'varchar', size: 50, unique: true),
            new Column('email', 'varchar', size: 255, unique: true),
            new Column('password', 'char', size: 60),
            new Column('role', 'varchar', size: 20, default: 'user'),
            new Column('status', 'varchar', size: 20, default: 'active'),
            new Column('bio', 'text', nullable: true),
            new Column('created_at', 'datetime'),
            new Column('updated_at', 'datetime'),
        ],
        indexes: [
            'idx_user_email' => ['email'],
            'idx_user_status' => ['status'],
        ]
    ),

    new Table(
        name: 'posts',
        columns: [
            new Column('id', 'id', primary: true, autoIncrement: true),
            new Column('title', 'varchar', size: 255),
            new Column('slug', 'varchar', size: 100, unique: true),
            new Column('content', 'text'),
            new Column('status', 'varchar', size: 20, default: 'draft'),
            new Column('author_id', 'integer'),
            new Column('published_at', 'datetime', nullable: true),
            new Column('created_at', 'datetime'),
            new Column('updated_at', 'datetime'),
        ],
        indexes: [
            'idx_post_slug' => ['slug'],
            'idx_post_author' => ['author_id'],
            'idx_post_status' => ['status'],
        ]
    ),
];