<?php
declare(strict_types=1);
namespace Neos\EventStore\Helper;

use Neos\EventStore\Model\EventEnvelope;
use Neos\EventStore\Model\EventStream\EventStreamInterface;
use Neos\EventStore\Model\Event\SequenceNumber;

/**
 * Implementation of an event stream that forwards iteration to a custom \Closure
 *
 * @internal This helper is mostly useful for testing purposes and should not be used in production
 */
final class ClosureEventStream implements EventStreamInterface
{

    private function __construct(
        private readonly \Closure $closure,
        private readonly ?SequenceNumber $minimumSequenceNumber,
        private readonly ?SequenceNumber $maximumSequenceNumber,
        private readonly ?int $limit,
        private readonly bool $backwards,
    ) {
    }

    /**
     * @param \Closure(?SequenceNumber, ?SequenceNumber, ?int, bool): \Traversable<EventEnvelope>  $closure
     */
    public static function create(\Closure $closure): self
    {
        return new self($closure, null, null, null, false);
    }

    public function withMinimumSequenceNumber(SequenceNumber $sequenceNumber): self
    {
        if ($this->minimumSequenceNumber !== null && $sequenceNumber->value === $this->minimumSequenceNumber->value) {
            return $this;
        }
        return new self($this->closure, $sequenceNumber, $this->maximumSequenceNumber, $this->limit, $this->backwards);
    }

    public function withMaximumSequenceNumber(SequenceNumber $sequenceNumber): self
    {
        if ($this->maximumSequenceNumber !== null && $sequenceNumber->value === $this->maximumSequenceNumber->value) {
            return $this;
        }
        return new self($this->closure, $this->minimumSequenceNumber, $sequenceNumber, $this->limit, $this->backwards);
    }

    public function limit(int $limit): self
    {
        if ($limit === $this->limit) {
            return $this;
        }
        return new self($this->closure, $this->minimumSequenceNumber, $this->maximumSequenceNumber, $limit, $this->backwards);
    }

    public function backwards(): self
    {
        if ($this->backwards) {
            return $this;
        }
        return new self($this->closure, $this->minimumSequenceNumber, $this->maximumSequenceNumber, $this->limit, true);
    }

    public function getIterator(): \Traversable
    {
        yield from ($this->closure)($this->minimumSequenceNumber, $this->maximumSequenceNumber, $this->limit, $this->backwards);
    }
}
