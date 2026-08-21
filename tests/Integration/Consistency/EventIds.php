<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\Model\Event\EventId;
use Neos\EventStore\Model\EventsForStreams;

/**
 * The ids of all events of one commit, in commit order
 *
 * Logged alongside the attempt so that the validator can tell "the right events in the wrong order" from
 * "other events entirely" when it reads the commit back from the store.
 *
 * @implements \IteratorAggregate<int, EventId>
 */
final readonly class EventIds implements \IteratorAggregate, \Countable
{
    /**
     * @param list<EventId> $items
     */
    private function __construct(
        private array $items,
    ) {
    }

    public static function create(EventId ...$items): self
    {
        return new self(array_values($items));
    }

    public static function fromEventsForStreams(EventsForStreams $eventsForStreams): self
    {
        $eventIds = [];
        foreach ($eventsForStreams as $eventsForStream) {
            foreach ($eventsForStream->events as $event) {
                $eventIds[] = $event->id;
            }
        }
        return new self($eventIds);
    }

    /**
     * @param list<string> $values
     */
    public static function fromStrings(array $values): self
    {
        return new self(array_map(EventId::fromString(...), $values));
    }

    public function at(int $index): ?EventId
    {
        return $this->items[$index] ?? null;
    }

    /**
     * @return list<string>
     */
    public function toStringList(): array
    {
        return array_map(static fn (EventId $eventId) => $eventId->value, $this->items);
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
