<?php

namespace Quarry\Schema;

class Column
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $primary = false,
        public bool $autoIncrement = false,
        public bool $unique = false,
        public bool $nullable = false,
        public mixed $default = null,
        public ?int $size = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public ?array $enumValues = null,
        public ?string $foreign = null,
        public ?string $onDelete = null,
        public ?string $onUpdate = null
    ) {}
}