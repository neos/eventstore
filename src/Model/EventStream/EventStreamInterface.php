<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\EventStream;

use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\EventEnvelope;

/**
 * Common interface for event streams
 * @api
 * @extends \IteratorAggregate<EventEnvelope>
 */
interface EventStreamInterface extends \IteratorAggregate
{
    public function withMinimumSequenceNumber(SequenceNumber $sequenceNumber): self;
    public function withMaximumSequenceNumber(SequenceNumber $sequenceNumber): self;
    public function limit(int $limit): self;
    public function backwards(): self;

    /**
     * @return \Traversable|EventEnvelope[]
     */
    public function getIterator(): \Traversable;
}
