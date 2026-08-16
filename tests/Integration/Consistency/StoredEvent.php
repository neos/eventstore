<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\Model\Event\EventId;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Event\Version;
use Neos\EventStore\Model\EventEnvelope;

/**
 * One event as it was read back from the store, together with the payload that ties it to its commit
 */
final readonly class StoredEvent
{
    private function __construct(
        public EventId $id,
        public StreamName $streamName,
        public Version $version,
        public SequenceNumber $sequenceNumber,
        public EventPayload $payload,
    ) {
    }

    public static function create(EventEnvelope $eventEnvelope, EventPayload $payload): self
    {
        return new self(
            $eventEnvelope->event->id,
            $eventEnvelope->streamName,
            $eventEnvelope->version,
            $eventEnvelope->sequenceNumber,
            $payload,
        );
    }
}
