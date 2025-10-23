<?php

namespace Quarry\Schema;

class Table
{
    public function __construct(
        public string $name,
        public array $columns = [],
        public array $indexes = [],
        public array $relationships = [],
        public string $engine = 'InnoDB'
    ) {}
}