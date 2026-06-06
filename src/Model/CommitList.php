<?php
declare(strict_types=1);

namespace Neos\EventStore\Model;

use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\EventStream\ExpectedVersion;

/**
 * @implements \IteratorAggregate<Commit>
 */
final readonly class CommitList implements \IteratorAggregate, \Countable
{
    /** @param non-empty-list<Commit> $items */
    private function __construct(
        public array $items
    ) {
    }

    public static function create(Commit $commit, Commit ...$commits): self
    {
        return new self([$commit, ...array_values($commits)]);
    }

    public static function createForEventsForStream(StreamName $streamName, Event|Events $events, ExpectedVersion $expectedVersion): self
    {
        return new self([
            new Commit(
                streamName: $streamName,
                events: $events instanceof Events ? $events : Events::with($events),
                expectedVersion: $expectedVersion,
            )
        ]);
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
