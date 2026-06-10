<?php
declare(strict_types=1);

namespace Neos\EventStore\Model;

/**
 * @implements \IteratorAggregate<int,EventsForStream>
 */
final readonly class EventsForStreams implements \IteratorAggregate, \Countable
{
    /** @param list<EventsForStream> $items */
    private function __construct(
        public array $items
    ) {
    }

    public static function create(EventsForStream $first, EventsForStream ...$items): self
    {
        return new self([$first, ...array_values($items)]);
    }

    public function withAppended(EventsForStream $item): self
    {
        return new self([...$this->items, $item]);
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
