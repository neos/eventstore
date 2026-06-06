<?php
declare(strict_types=1);
namespace Neos\EventStore\Adapter;

use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Exception\ConcurrencyException;
use Neos\EventStore\Model\Commit;
use Neos\EventStore\Model\CommitList;
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
        return $this->commitAll(CommitList::createForEventsForStream(
            streamName: $streamName,
            events: $events,
            expectedVersion: $expectedVersion,
        ))->first();
    }

    public function commitAll(CommitList $commits): CommitAllResult
    {
        // validation
        $newStreamVersions = [];
        foreach ($commits as $index => $commit) {
            $maybeVersion = MaybeVersion::fromVersionOrNull($newStreamVersions[$commit->streamName->value] ?? $this->streamVersions[$commit->streamName->value] ?? null);
            if (!$commit->expectedVersion->isSatisfiedBy($maybeVersion)) {
                if ($commits->count() === 1) {
                    throw ConcurrencyException::becauseVersionOfStreamDoesNotMatchExpected($commit->expectedVersion, $maybeVersion, $commit->streamName);
                } else {
                    throw ConcurrencyException::becauseVersionOfStreamDoesNotMatchExpectedCommitAll($commit->expectedVersion, $maybeVersion, $commit->streamName, $index + 1, $commits->count());
                }
            }
            $previousVersion = $maybeVersion->nextVersionOrFirst();
            $nextVersion = $previousVersion->add(Version::fromInteger($commit->events->count()));
            $newStreamVersions[$commit->streamName->value] = $nextVersion;
        }

        // commiting
        $newStreamVersions = [];
        foreach ($commits as $commit) {
            $maybeVersion = $this->getStreamVersion($commit->streamName);
            $version = $maybeVersion->nextVersionOrFirst();
            $this->sequenceNumber ??= SequenceNumber::none();
            foreach ($commit->events as $event) {
                $this->sequenceNumber = $this->sequenceNumber->next();
                $this->streamVersions[$commit->streamName->value] = $version;
                $this->events[] = new EventEnvelope(
                    new Event(
                        $event->id,
                        $event->type,
                        $event->data,
                        $event->metadata,
                        $event->causationId,
                        $event->correlationId,
                    ),
                    $commit->streamName,
                    $version,
                    $this->sequenceNumber,
                    $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))
                );
                $version = $version->next();
                $newStreamVersions[$commit->streamName->value] = new VersionForStream($commit->streamName, $version);
            }
        }

        return CommitAllResult::create($this->sequenceNumber, VersionForStreams::create(...$newStreamVersions));
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
