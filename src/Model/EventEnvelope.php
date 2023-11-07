<?php
declare(strict_types=1);
namespace Neos\EventStore\Model;

use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Event\Version;
use Neos\EventStore\Model\EventStream\EventStreamInterface;

/**
 * returned when iterating over the {@see EventStreamInterface} (read side(
 */
final class EventEnvelope
{
    public function __construct(
        public readonly Event $event,
        public readonly StreamName $streamName,
        public readonly Version $version,
        public readonly SequenceNumber $sequenceNumber,
        public readonly \DateTimeImmutable $recordedAt,
    ) {
    }
}
