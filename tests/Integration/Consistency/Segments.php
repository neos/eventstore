<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\EventsForStreams;

/**
 * The layout of a commit: which streams get how many events, in commit order
 *
 * The same stream may appear more than once, which is exactly what makes the layout worth keeping: the
 * versions of such a stream have to continue across its segments rather than restart per segment.
 *
 * @implements \IteratorAggregate<int, Segment>
 */
final readonly class Segments implements \IteratorAggregate, \Countable
{
    /**
     * @param non-empty-list<Segment> $items
     */
    private function __construct(
        private array $items,
    ) {
    }

    public static function create(Segment ...$items): self
    {
        $items = array_values($items);
        if ($items === []) {
            throw new \RuntimeException('A commit has to consist of at least one segment', 1781013050);
        }
        return new self($items);
    }

    public static function fromEventsForStreams(EventsForStreams $eventsForStreams): self
    {
        $segments = [];
        foreach ($eventsForStreams as $eventsForStream) {
            $segments[] = Segment::create($eventsForStream->streamName, count($eventsForStream->events));
        }
        return self::create(...$segments);
    }

    /**
     * @param list<Json> $jsonList
     */
    public static function fromJsonList(array $jsonList): self
    {
        return self::create(...array_map(Segment::fromJson(...), $jsonList));
    }

    public function totalNumberOfEvents(): int
    {
        $total = 0;
        foreach ($this->items as $segment) {
            $total += $segment->numberOfEvents;
        }
        return $total;
    }

    /**
     * The stream each event of the commit belongs to, in commit order
     *
     * @return list<StreamName>
     */
    public function streamNamePerEvent(): array
    {
        $streamNames = [];
        foreach ($this->items as $segment) {
            for ($i = 0; $i < $segment->numberOfEvents; $i++) {
                $streamNames[] = $segment->streamName;
            }
        }
        return $streamNames;
    }

    /**
     * @return list<array{stream: string, numberOfEvents: int}>
     */
    public function toArray(): array
    {
        return array_map(static fn (Segment $segment) => $segment->toArray(), $this->items);
    }

    public function toDebugString(): string
    {
        return implode(' + ', array_map(static fn (Segment $segment) => $segment->toDebugString(), $this->items));
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
