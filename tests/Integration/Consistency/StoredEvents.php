<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\EventStream\MaybeVersion;

/**
 * A group of events read back from the store, in the global order of the store
 *
 * Every group is built in one go from a complete list – the indexes of {@see StoreContents} bucket the
 * events into plain arrays while reading and wrap each bucket once, so no group is ever appended to after
 * it exists. Ascending sequence numbers are therefore an invariant of every group, and the first event is
 * the one the store published first.
 *
 * Some groups additionally hold a single stream only, which is what makes {@see streamName()} and
 * {@see versionBefore()} meaningful. Those are the groups {@see groupedByStreamName()} and
 * {@see StoreContents::eventsOfStream()} return.
 *
 * @implements \IteratorAggregate<int, StoredEvent>
 */
final readonly class StoredEvents implements \IteratorAggregate, \Countable
{
    /**
     * @param list<StoredEvent> $items
     */
    private function __construct(
        private array $items,
    ) {
    }

    /**
     * @param list<StoredEvent> $items in the global order of the store
     *
     * The order is not verified: a store that publishes events out of order is a violation to be reported
     * {@see Violation::SEQUENCE_NOT_ASCENDING} rather than an error to fail the validation run with.
     */
    public static function fromArray(array $items): self
    {
        return new self($items);
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * The event of this group the store published first
     */
    public function first(): StoredEvent
    {
        return $this->items[0] ?? throw new \RuntimeException('There is no first event of an empty group', 1781013051);
    }

    /**
     * The event of this group the store published last
     */
    public function last(): StoredEvent
    {
        return $this->items[count($this->items) - 1] ?? throw new \RuntimeException('There is no last event of an empty group', 1781013052);
    }

    /**
     * The events in the order their commit declared, which is not necessarily the order they were found in
     *
     * Breaks the ascending-sequence-number invariant every other group has, so the result must never be
     * passed to {@see versionBefore()}, whose binary search depends on that invariant.
     */
    public function sortedByPositionInCommit(): self
    {
        $items = $this->items;
        usort($items, static fn (StoredEvent $left, StoredEvent $right) => $left->payload->positionInCommit <=> $right->payload->positionInCommit);
        return new self($items);
    }

    /**
     * This group split into one group per stream, each keeping the order of this one
     *
     * @return list<self> in the order the streams were first encountered
     */
    public function groupedByStreamName(): array
    {
        $buckets = [];
        foreach ($this->items as $event) {
            $buckets[$event->streamName->value][] = $event;
        }
        return array_values(array_map(self::fromArray(...), $buckets));
    }

    /**
     * Whether the versions ascend by exactly one from event to event
     *
     * Only meaningful for a group that holds a single stream {@see groupedByStreamName()}
     */
    public function versionsAreConsecutive(): bool
    {
        $previous = null;
        foreach ($this->items as $event) {
            if ($previous !== null && $event->version->value !== $previous->value + 1) {
                return false;
            }
            $previous = $event->version;
        }
        return true;
    }

    public function versionsToDebugString(): string
    {
        return implode(', ', array_map(static fn (StoredEvent $event) => $event->version->value, $this->items));
    }

    /**
     * The version the stream of these events had strictly before the given point in the global order
     *
     * Only meaningful for a group that holds a single stream {@see StoreContents::eventsOfStream()}
     *
     * A binary search rather than a scan, because it is asked once per constraint of every successful
     * commit, against streams that can hold tens of thousands of events. Requires ascending sequence
     * numbers, so never call this on the result of {@see sortedByPositionInCommit()}.
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

    /**
     * The stream these events belong to
     *
     * Only meaningful for a group that holds a single stream {@see groupedByStreamName()}
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
