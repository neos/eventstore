<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Event\Version;
use Neos\EventStore\Model\EventStream\MaybeVersion;

/**
 * Events read back from the store, in ascending sequence number order
 *
 * Deliberately mutable: the validator reads the whole store once and files every event into the group of
 * its stream and the group of its commit, and copying a group of thousands of events per appended event
 * would turn that single pass into a quadratic one.
 *
 * @implements \IteratorAggregate<int, StoredEvent>
 */
final class StoredEvents implements \IteratorAggregate, \Countable
{
    /**
     * @param list<StoredEvent> $items
     */
    private function __construct(
        private array $items,
    ) {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Appends an event that follows all previously appended ones in the global order of the store
     */
    public function append(StoredEvent $event): void
    {
        $this->items[] = $event;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function first(): StoredEvent
    {
        return $this->items[0] ?? throw new \RuntimeException('There is no first event of an empty group', 1781013051);
    }

    public function last(): StoredEvent
    {
        return $this->items[count($this->items) - 1] ?? throw new \RuntimeException('There is no last event of an empty group', 1781013052);
    }

    /**
     * The events in the order their commit declared, which is not necessarily the order they were found in
     */
    public function sortedByPositionInCommit(): self
    {
        $items = $this->items;
        usort($items, static fn (StoredEvent $left, StoredEvent $right) => $left->payload->positionInCommit <=> $right->payload->positionInCommit);
        return new self($items);
    }

    /**
     * @return array<string, self> keyed by stream name, in the order the streams were first encountered
     */
    public function groupedByStreamName(): array
    {
        $groups = [];
        foreach ($this->items as $event) {
            $groups[$event->streamName->value] ??= self::none();
            $groups[$event->streamName->value]->append($event);
        }
        return $groups;
    }

    /**
     * @return list<Version>
     */
    public function versions(): array
    {
        return array_map(static fn (StoredEvent $event) => $event->version, $this->items);
    }

    /**
     * The version the stream of these events had strictly before the given point in the global order
     *
     * A binary search rather than a scan, because it is asked once per constraint of every successful
     * commit, against streams that can hold tens of thousands of events.
     */
    public function versionBefore(SequenceNumber $sequenceNumber): MaybeVersion
    {
        $low = 0;
        $high = count($this->items) - 1;
        $version = null;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            if ($this->items[$middle]->sequenceNumber->value >= $sequenceNumber->value) {
                $high = $middle - 1;
                continue;
            }
            $version = $this->items[$middle]->version;
            $low = $middle + 1;
        }
        return MaybeVersion::fromVersionOrNull($version);
    }

    public function lowestSequenceNumber(): SequenceNumber
    {
        $lowest = null;
        foreach ($this->items as $event) {
            if ($lowest === null || $event->sequenceNumber->value < $lowest->value) {
                $lowest = $event->sequenceNumber;
            }
        }
        return $lowest ?? throw new \RuntimeException('An empty group of events has no sequence number', 1781013053);
    }

    /**
     * The stream these events belong to – only meaningful for a group that holds a single stream
     * {@see groupedByStreamName()}
     */
    public function streamName(): StreamName
    {
        return $this->first()->streamName;
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
