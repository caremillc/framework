<?php 
namespace Careminate\Databases\QueryBuilder;

class Collection implements \IteratorAggregate, \Countable
{
    public function __construct(protected array $items) {}

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
   
    public function toArray()
    {
        return array_map(function ($item) {
            return method_exists($item, 'toArray') ? $item->toArray() : (array)$item;
        }, $this->items);
    }

}
