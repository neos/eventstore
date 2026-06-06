<?php
declare(strict_types=1);

namespace Neos\EventStore\Model\EventStore;

/**
 * @implements \IteratorAggregate<int,VersionForStream>
 */
final readonly class VersionForStreams implements \IteratorAggregate, \Countable
{
    /** @param non-empty-list<VersionForStream> $items */
    private function __construct(
        public array $items
    ) {
    }

    public static function create(VersionForStream $first, VersionForStream ...$items): self
    {
        return new self([$first, ...array_values($items)]);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }
}
