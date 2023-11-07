<?php
declare(strict_types=1);
namespace Neos\EventStore\Model;

use Neos\EventStore\Model\Event\EventData;
use Neos\EventStore\Model\Event\EventId;
use Neos\EventStore\Model\Event\EventMetadata;
use Neos\EventStore\Model\Event\EventType;

/**
 * Main model for reading and writing (when reading, it is wrapped in {@see EventEnvelope})
 * @api
 */
final class Event
{
    public function __construct(
        public readonly EventId $id,
        public readonly EventType $type,
        public readonly EventData $data,
        public readonly EventMetadata $metadata,
    ) {
    }
}
