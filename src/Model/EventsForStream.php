<?php

declare(strict_types=1);

namespace Neos\EventStore\Model;

use Neos\EventStore\Model\Event\StreamName;

final readonly class EventsForStream
{
    /**
     * @param StreamName $streamName
     * @param Events $events
     */
    private function __construct(
        public StreamName $streamName,
        public Events $events,
    ) {
    }

    public static function create(
        StreamName $streamName,
        Events $events,
    ): self {
        return new self(
            streamName: $streamName,
            events: $events,
        );
    }
}
