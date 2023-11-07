<?php
declare(strict_types=1);
namespace Neos\EventStore\Model;

use Neos\EventStore\Model\Event\EventData;
use Neos\EventStore\Model\Event\EventId;
use Neos\EventStore\Model\Event\EventMetadata;
use Neos\EventStore\Model\Event\EventType;
use Webmozart\Assert\Assert;

/**
 * @implements \IteratorAggregate<Event>
 * @api
 */
final class Events implements \IteratorAggregate, \Countable
{
    /**
     * @var Event[]
     */
    private array $events;

    private function __construct(Event ...$events)
    {
        Assert::notEmpty($events, 'Writable events must contain at least one event');
        $this->events = $events;
    }

    public static function with(Event $event): self
    {
        return new self($event);
    }

    public static function single(EventId $id, EventType $type, EventData $data, EventMetadata $metadata): self
    {
        return new self(new Event($id, $type, $data, $metadata));
    }

    /**
     * @param Event[] $events
     * @return static
     */
    public static function fromArray(array $events): self
    {
        return new self(...$events);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->events);
    }

    public function count(): int
    {
        return count($this->events);
    }
}
