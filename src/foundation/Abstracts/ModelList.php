<?php

namespace SwooleBase\Foundation\Abstracts;

use ArrayAccess;
use Countable;
use Iterator;
use IteratorAggregate;
use JsonSerializable;

/**
 * Class ModelList
 * @package SwooleBase\Foundation\Support
 */
abstract class ModelList implements Countable, IteratorAggregate, ArrayAccess, JsonSerializable
{
    /** @var array */
    protected array $items;

    /** @var string */
    protected string $modelClass;

    /**
     * ModelList constructor.
     * @param array $items
     * @param string|null $modelClass
     */
    public function __construct(array $items, string $modelClass = null)
    {
        $this->items = $items;

        if ($modelClass) {
            $this->modelClass = $modelClass;
        }
    }

    /**
     * @return int
     */
    public final function count(): int
    {
        return count($this->items);
    }

    /**
     * @return mixed
     */
    public function first(): mixed
    {
        return $this->transform($this->items[array_key_first($this->items)]);
    }

    public function last()
    {
        return $this->transform($this->items[array_key_last($this->items)]);
    }

    /**
     * @return Iterator
     */
    public final function getIterator(): Iterator
    {
        foreach ($this->items as $key => $item) {
            yield $key => $this->transform($item);
        }
    }

    /**
     * Checks if an offset exists.
     *
     * @param mixed $offset
     * @return bool
     */
    public final function offsetExists($offset): bool
    {
        return isset($this->items[$offset]);
    }

    /**
     * Gets the value at an offset.
     *
     * @param mixed $offset
     * @return mixed
     */
    public final function offsetGet($offset)
    {
        return $this->items[$offset] ?? null;
    }

    /**
     * Sets the value at an offset.
     *
     * @param mixed $offset
     * @param mixed $value
     */
    public final function offsetSet($offset, $value): void
    {
        if (is_null($offset)) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    /**
     * Unsets the value at an offset.
     *
     * @param mixed $offset
     */
    public final function offsetUnset($offset): void
    {
        unset($this->items[$offset]);
    }

    public final function jsonSerialize()
    {
        return iterator_to_array($this->getIterator());
    }

    protected function transform($item): mixed
    {
        return new $this->modelClass($item);
    }
}
