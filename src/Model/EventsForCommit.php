<?php
declare(strict_types=1);

namespace Neos\EventStore\Model;

use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\EventStream\ExpectedVersion;
use Neos\EventStore\Model\EventStream\ExpectedVersionForStreams;

final readonly class EventsForCommit
{
    private function __construct(
        public EventsForStreams $eventsForStreams,
        public ExpectedVersionForStreams $expectedVersionForStreams,
    ) {
    }

    public static function create(
        EventsForStreams $items,
        ExpectedVersionForStreams $expectedVersionForStreams,
    ): self {
        return new self(
            eventsForStreams: $items,
            expectedVersionForStreams: $expectedVersionForStreams,
        );
    }

    public static function createEventsForStream(StreamName $streamName, Event|Events $events): self
    {
        return new self(
            EventsForStreams::create(
                EventsForStream::create(
                    streamName: $streamName,
                    events: $events instanceof Events ? $events : Events::with($events),
                ),
            ),
            ExpectedVersionForStreams::create()
        );
    }

    public static function createEventsForStreamAndExpectedVersion(StreamName $streamName, Event|Events $events, ExpectedVersion $expectedVersion): self
    {
        $expectedVersionForStream = $expectedVersion->toExpectedStreamVersion($streamName);

        return new self(
            EventsForStreams::create(
                EventsForStream::create(
                    streamName: $streamName,
                    events: $events instanceof Events ? $events : Events::with($events),
                ),
            ),
            $expectedVersionForStream === null
                ? ExpectedVersionForStreams::create()
                : ExpectedVersionForStreams::create($expectedVersionForStream)
        );
    }

    public function withEventsForStreamAndExpectedVersion(StreamName $streamName, Event|Events $events, ExpectedVersion $expectedVersion): self
    {
        $expectedVersionForStream = $expectedVersion->toExpectedStreamVersion($streamName);

        return new self(
            $this->eventsForStreams->withAppended(
                EventsForStream::create(
                    streamName: $streamName,
                    events: $events instanceof Events ? $events : Events::with($events),
                ),
            ),
            $expectedVersionForStream === null
                ? $this->expectedVersionForStreams
                : $this->expectedVersionForStreams->withAppended($expectedVersionForStream),
        );
    }

    public function withEventsForStream(StreamName $streamName, Event|Events $events): self
    {
        return new self(
            $this->eventsForStreams->withAppended(
                EventsForStream::create(
                    streamName: $streamName,
                    events: $events instanceof Events ? $events : Events::with($events),
                ),
            ),
            $this->expectedVersionForStreams
        );
    }

    public function withExpectedVersionForStream(StreamName $streamName, ExpectedVersion $expectedVersion): self
    {
        $expectedVersionForStream = $expectedVersion->toExpectedStreamVersion($streamName);
        if ($expectedVersionForStream === null) {
            return $this;
        }

        return new self(
            $this->eventsForStreams,
            $this->expectedVersionForStreams->withAppended(
                $expectedVersionForStream
            )
        );
    }
}
