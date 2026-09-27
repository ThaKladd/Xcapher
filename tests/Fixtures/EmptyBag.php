<?php

declare(strict_types=1);

namespace Xcapher\Tests\Fixtures;

/**
 * @implements \IteratorAggregate<never, never>
 */
final class EmptyBag implements \Countable, \IteratorAggregate
{
    public function count(): int
    {
        return 0;
    }

    public function getIterator(): \Iterator
    {
        return new \EmptyIterator();
    }
}
