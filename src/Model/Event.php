<?php
declare(strict_types=1);
namespace Neos\EventStore\Model;

use Neos\EventStore\Model\Event\CausationId;
use Neos\EventStore\Model\Event\CorrelationId;
use Neos\EventStore\Model\Event\EventData;
use Neos\EventStore\Model\Event\EventId;
use Neos\EventStore\Model\Event\EventMetadata;
use Neos\EventStore\Model\Event\EventType;

/**
 * Main model for reading and writing (when reading, it is wrapped in {@see EventEnvelope})
 * @api
 */
final readonly class Event
{
    public function __construct(
        public EventId $id,
        public EventType $type,
        public EventData $data,
        public ?EventMetadata $metadata = null,
        public ?CausationId $causationId = null,
        public ?CorrelationId $correlationId = null,
    ) {
    }
}
