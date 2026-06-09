<?php
declare(strict_types=1);
namespace Neos\EventStore\Adapter;

use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Exception\ConcurrencyException;
use Neos\EventStore\Model\EventsForCommit;
use Neos\EventStore\Model\Event;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Event\Version;
use Neos\EventStore\Model\EventEnvelope;
use Neos\EventStore\Model\Events;
use Neos\EventStore\Model\EventStore\CommitAllResult;
use Neos\EventStore\Model\EventStore\CommitResult;
use Neos\EventStore\Model\EventStore\Status;
use Neos\EventStore\Model\EventStore\VersionForStream;
use Neos\EventStore\Model\EventStore\VersionForStreams;
use Neos\EventStore\Model\EventStream\EventStreamFilter;
use Neos\EventStore\Model\EventStream\EventStreamInterface;
use Neos\EventStore\Model\EventStream\ExpectedVersion;
use Neos\EventStore\Model\EventStream\MaybeVersion;
use Neos\EventStore\Model\EventStream\VirtualStreamName;
use Neos\EventStore\Model\EventStream\VirtualStreamType;
use Neos\EventStore\WithResetInterface;
use Psr\Clock\ClockInterface;

/**
 * In-memory implementation of an event store
 *
 * @internal exposed for testing and experimental use cases. Memory footprint and performance with large data untested.
 */
final class InMemoryEventStore implements EventStoreInterface, WithResetInterface
{
    /**
     * @var EventEnvelope[]
     */
    private array $events = [];

    /**
     * @var array<string,Version>
     */
    private array $streamVersions = [];

    private ?SequenceNumber $sequenceNumber = null;

    public function __construct(
        private readonly ClockInterface $clock
    ) {
    }

    public function setup(): void
    {
        // nothing to do
    }

    public function status(): Status
    {
        return Status::ok();
    }

    public function load(VirtualStreamName|StreamName $streamName, ?EventStreamFilter $filter = null): EventStreamInterface
    {
        $events = match ($streamName::class) {
            StreamName::class => array_filter($this->events, static fn (EventEnvelope $event) => $event->streamName->equals($streamName)),
            VirtualStreamName::class => match ($streamName->type) {
                VirtualStreamType::ALL => $this->events,
                VirtualStreamType::CATEGORY => array_filter($this->events, static fn (EventEnvelope $event) => str_starts_with($event->streamName->value, $streamName->value)),
                VirtualStreamType::CORRELATION_ID => array_filter($this->events, static fn (EventEnvelope $eventEnvelope) => $eventEnvelope->event->correlationId?->value === $streamName->value),
            },
        };
        if ($filter !== null && $filter->eventTypes !== null) {
            $events = array_filter($events, static fn (EventEnvelope $event) => $filter->eventTypes->contains($event->event->type));
        }
        return InMemoryEventStream::create(...$events);
    }

    public function commit(StreamName $streamName, Event|Events $events, ExpectedVersion $expectedVersion): CommitResult
    {
        return CommitResult::fromCommitAll($this->commitAll(EventsForCommit::createEventsForStreamAndExpectedVersion(
            streamName: $streamName,
            events: $events,
            expectedVersion: $expectedVersion,
        )));
    }

    public function commitAll(EventsForCommit $commit): CommitAllResult
    {
        // validation
        foreach ($commit->expectedVersionForStreams as $expectedVersionForStream) {
            $maybeVersion = MaybeVersion::fromVersionOrNull($this->streamVersions[$expectedVersionForStream->streamName->value] ?? null);
            if (!$expectedVersionForStream->isSatisfiedBy($maybeVersion)) {
                throw ConcurrencyException::becauseVersionOfStreamDoesNotMatchExpected($expectedVersionForStream, $maybeVersion, $commit->expectedVersionForStreams);
            }
        }

        // commiting
        $newStreamVersions = [];
        foreach ($commit->eventsForStreams as $eventsForStream) {
            $maybeVersion = $this->getStreamVersion($eventsForStream->streamName);
            $version = $maybeVersion->nextVersionOrFirst();
            $this->sequenceNumber ??= SequenceNumber::none();
            foreach ($eventsForStream->events as $event) {
                $this->sequenceNumber = $this->sequenceNumber->next();
                $this->streamVersions[$eventsForStream->streamName->value] = $version;
                $this->events[] = new EventEnvelope(
                    new Event(
                        $event->id,
                        $event->type,
                        $event->data,
                        $event->metadata,
                        $event->causationId,
                        $event->correlationId,
                    ),
                    $eventsForStream->streamName,
                    $version,
                    $this->sequenceNumber,
                    $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))
                );
                $newStreamVersions[$eventsForStream->streamName->value] = VersionForStream::create($eventsForStream->streamName, $version);
                $version = $version->next();
            }
        }
        // Always set, as at least one iteration
        assert($this->sequenceNumber !== null);
        return CommitAllResult::create($this->sequenceNumber, VersionForStreams::create(...array_values($newStreamVersions)));
    }

    public function deleteStream(StreamName $streamName): void
    {
        foreach ($this->events as $index => $event) {
            if ($event->streamName->equals($streamName)) {
                unset($this->events[$index]);
            }
        }
        unset($this->streamVersions[$streamName->value]);
    }

    public function reset(): void
    {
        $this->events = [];
        $this->sequenceNumber = null;
    }

    private function getStreamVersion(StreamName $streamName): MaybeVersion
    {
        return MaybeVersion::fromVersionOrNull($this->streamVersions[$streamName->value] ?? null);
    }
}
