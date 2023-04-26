<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\EventStream;

use Neos\EventStore\Model\Event\EventTypes;
use Webmozart\Assert\Assert;

/**
 * TODO
 */
final class EventStreamFilter
{
    private function __construct(
        public readonly ?EventTypes $eventTypes,
    ) {
    }

    public static function forEventTypes(EventTypes $eventTypes): self
    {
        return new self($eventTypes);
    }
}
