<?php
declare(strict_types=1);
namespace Neos\EventStore\Model;

use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Event\Version;
use Neos\EventStore\Model\EventStream\EventStreamInterface;

/**
 * returned when iterating over the {@see EventStreamInterface}
 * @api
 */
final class EventEnvelope
{
    /**
     * @param Event $event The actual event
     * @param StreamName $streamName The name of stream that this event is stored in
     * @param Version $version The version of this even in its stream
     * @param SequenceNumber $sequenceNumber The global sequence number of this event
     * @param \DateTimeImmutable $recordedAt The point in time this event has been persisted at
     */
    public function __construct(
        public readonly Event $event,
        public readonly StreamName $streamName,
        public readonly Version $version,
        public readonly SequenceNumber $sequenceNumber,
        public readonly \DateTimeImmutable $recordedAt,
    ) {
    }
}
