<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\EventStream\VirtualStreamName;

/**
 * Everything the store holds when a run is over, indexed by commit and by stream
 *
 * Built in a single pass in the global order of the store, so that both indexes come out in that order –
 * which is what allows the checks to reason about *when* a commit landed. Events that cannot belong to
 * this run are reported as orphans while reading instead of being indexed. The pass buckets into plain
 * arrays and wraps each bucket into {@see StoredEvents} once at the end, so no group is ever grown after
 * it exists.
 */
final readonly class StoreContents
{
    /**
     * @param array<string, StoredEvents> $eventsByCommitId
     * @param array<string, StoredEvents> $eventsByStreamName
     */
    private function __construct(
        private array $eventsByCommitId,
        private array $eventsByStreamName,
    ) {
    }

    public static function read(EventStoreInterface $eventStore, RunManifest $manifest, Attempts $attempts, ValidationReport $report): self
    {
        /** @var array<string, list<StoredEvent>> $eventsByCommitId */
        $eventsByCommitId = [];
        /** @var array<string, list<StoredEvent>> $eventsByStreamName */
        $eventsByStreamName = [];
        $lastSequenceNumber = SequenceNumber::none();
        $numberOfEvents = 0;
        foreach ($eventStore->load(VirtualStreamName::all()) as $eventEnvelope) {
            $numberOfEvents++;
            $sequenceNumber = $eventEnvelope->sequenceNumber;
            if ($sequenceNumber->value <= $lastSequenceNumber->value) {
                $report->addViolation(Violation::SEQUENCE_NOT_ASCENDING, sprintf(
                    'Event "%s" has sequence number %d which does not exceed the previous one (%d)',
                    $eventEnvelope->event->id->value,
                    $sequenceNumber->value,
                    $lastSequenceNumber->value,
                ));
            }
            $lastSequenceNumber = $sequenceNumber;

            $payload = EventPayload::tryFromEventData($eventEnvelope->event->data);
            if ($payload === null) {
                $report->addViolation(Violation::ORPHAN_EVENT, sprintf(
                    'Event "%s" in stream "%s" (sequence number %d) has a payload that was not written by this harness: %s',
                    $eventEnvelope->event->id->value,
                    $eventEnvelope->streamName->value,
                    $sequenceNumber->value,
                    substr($eventEnvelope->event->data->value, 0, 80),
                ));
                continue;
            }
            $storedEvent = StoredEvent::create($eventEnvelope, $payload);
            $eventsByStreamName[$storedEvent->streamName->value][] = $storedEvent;

            if ($payload->runId !== $manifest->runId) {
                $report->addViolation(Violation::ORPHAN_EVENT, sprintf(
                    'Event "%s" in stream "%s" belongs to run "%s" instead of "%s" – the store was not reset',
                    $storedEvent->id->value,
                    $storedEvent->streamName->value,
                    $payload->runId,
                    $manifest->runId,
                ));
                continue;
            }
            if (!$attempts->has($payload->commitId)) {
                $report->addViolation(Violation::ORPHAN_EVENT, sprintf(
                    'Event "%s" in stream "%s" claims commit "%s" which was never logged',
                    $storedEvent->id->value,
                    $storedEvent->streamName->value,
                    $payload->commitId,
                ));
                continue;
            }
            $eventsByCommitId[$payload->commitId][] = $storedEvent;
        }
        $report->addNote(sprintf('%d events in store across %d streams', $numberOfEvents, count($eventsByStreamName)));
        return new self(
            array_map(StoredEvents::fromArray(...), $eventsByCommitId),
            array_map(StoredEvents::fromArray(...), $eventsByStreamName),
        );
    }

    /**
     * The events one commit wrote, in the global order of the store – empty for a commit that wrote none
     */
    public function eventsOfCommit(string $commitId): StoredEvents
    {
        return $this->eventsByCommitId[$commitId] ?? StoredEvents::none();
    }

    /**
     * The events of one stream, in the global order of the store – empty for a stream that has none
     */
    public function eventsOfStream(StreamName $streamName): StoredEvents
    {
        return $this->eventsByStreamName[$streamName->value] ?? StoredEvents::none();
    }

    /**
     * The events of every stream that received any, for checks that are not about a particular commit
     *
     * @return list<StoredEvents>
     */
    public function perStream(): array
    {
        return array_values($this->eventsByStreamName);
    }
}
