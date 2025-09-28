<?php

namespace Quarry\Entity;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

class Collection implements Countable, IteratorAggregate
{
    protected array $items = [];

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public function all(): array
    {
        return $this->items;
    }

    public function first(): ?Entity
    {
        return $this->items[0] ?? null;
    }

    public function last(): ?Entity
    {
        $count = count($this->items);
        return $count > 0 ? $this->items[$count - 1] : null;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}