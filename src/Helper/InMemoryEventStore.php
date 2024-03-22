<?php
declare(strict_types=1);
namespace Neos\EventStore\Helper;

use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\Event;
use Neos\EventStore\Model\EventStore\CommitResult;
use Neos\EventStore\Model\EventStore\Status;
use Neos\EventStore\Model\EventStream\EventStreamFilter;
use Neos\EventStore\Model\EventStream\EventStreamInterface;
use Neos\EventStore\Model\EventStream\ExpectedVersion;
use Neos\EventStore\Model\EventStream\MaybeVersion;
use Neos\EventStore\Model\EventEnvelope;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Event\Version;
use Neos\EventStore\Model\EventStream\VirtualStreamName;
use Neos\EventStore\Model\EventStream\VirtualStreamType;
use Neos\EventStore\Model\Events;

/**
 * In-memorry implementation of an event store
 *
 * @internal This helper is mostly useful for testing purposes and should not be used in production
 */
final class InMemoryEventStore implements EventStoreInterface
{
    /**
     * @var EventEnvelope[]
     */
    private array $events = [];

    private ?SequenceNumber $sequenceNumber = null;

    public function setup(): void
    {
        // nothing to do
    }

    public function status(): Status
    {
        return Status::ok();
    }

    public function load(VirtualStreamName|StreamName $streamName, EventStreamFilter $filter = null): EventStreamInterface
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
        if ($events instanceof Event) {
            $events = Events::fromArray([$events]);
        }
        $maybeVersion = $this->getStreamVersion($streamName);
        $expectedVersion->verifyVersion($maybeVersion);
        $version = $maybeVersion->isNothing() ? Version::first() : $maybeVersion->unwrap()->next();
        $now = new \DateTimeImmutable();
        $this->sequenceNumber = $this->sequenceNumber ?? SequenceNumber::none();
        $lastCommittedVersion = $version;
        foreach ($events as $event) {
            $this->sequenceNumber = $this->sequenceNumber->next();
            $this->events[] = new EventEnvelope(
                new Event(
                    $event->id,
                    $event->type,
                    $event->data,
                    $event->metadata,
                    $event->causationId,
                    $event->correlationId,
                ),
                $streamName,
                $version,
                $this->sequenceNumber,
                $now
            );
            $lastCommittedVersion = $version;
            $version = $version->next();
        }

        return new CommitResult($lastCommittedVersion, $this->sequenceNumber);
    }

    public function deleteStream(StreamName $streamName): void
    {
        foreach ($this->events as $index => $event) {
            if ($event->streamName->equals($streamName)) {
                unset($this->events[$index]);
            }
        }
    }

    private function getStreamVersion(StreamName $streamName): MaybeVersion
    {
        /** @var Version|null $version */
        $version = null;
        foreach ($this->events as $event) {
            if ($event->streamName->equals($streamName)) {
                $version = $event->version;
            }
        }
        return MaybeVersion::fromVersionOrNull($version);
    }
}
