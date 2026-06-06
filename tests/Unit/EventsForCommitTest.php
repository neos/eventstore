<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Unit;

use Neos\EventStore\Model\Event;
use Neos\EventStore\Model\Event\EventData;
use Neos\EventStore\Model\Event\EventId;
use Neos\EventStore\Model\Event\EventType;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Events;
use Neos\EventStore\Model\EventsForCommit;
use Neos\EventStore\Model\EventsForStream;
use Neos\EventStore\Model\EventsForStreams;
use Neos\EventStore\Model\EventStream\ExpectedVersion;
use Neos\EventStore\Model\EventStream\ExpectedVersionForStream;
use Neos\EventStore\Model\EventStream\ExpectedVersionForStreams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventsForCommit::class)]
class EventsForCommitTest extends TestCase
{
    public function test_illegal_empty_list(): void
    {
        $this->expectException(\TypeError::class);

        EventsForCommit::create(
            /** @phpstan-ignore-next-line */
            EventsForStreams::create(),
            /** @phpstan-ignore-next-line */
            ExpectedVersionForStreams::create(),
        );
    }

    public function test_append(): void
    {
        $expected = EventsForCommit::create(
            EventsForStreams::create(
                EventsForStream::create(
                    StreamName::fromString('stream-1'),
                    Events::with(new Event(
                        $id1 = EventId::create(),
                        EventType::fromString('SomeEventType'),
                        EventData::fromString('a'),
                    )),
                ),
                EventsForStream::create(
                    StreamName::fromString('stream-2'),
                    Events::with(new Event(
                        $id2 =EventId::create(),
                        EventType::fromString('SomeOther'),
                        EventData::fromString('a'),
                    )),
                ),
            ),
            ExpectedVersionForStreams::create(
                ExpectedVersionForStream::create(
                    StreamName::fromString('stream-1'),
                    ExpectedVersion::NO_STREAM(),
                ),
                ExpectedVersionForStream::create(
                    StreamName::fromString('stream-2'),
                    ExpectedVersion::fromVersion(Event\Version::fromInteger(20))
                )
            ),
        );

        $actual = EventsForCommit::createEventsForStreamAndExpectedVersion(
            StreamName::fromString('stream-1'),
            Events::with(new Event(
                $id1,
                EventType::fromString('SomeEventType'),
                EventData::fromString('a'),
            )),
            ExpectedVersion::NO_STREAM()
        )->withEventsForStreamAndExpectedVersion(
            StreamName::fromString('stream-2'),
            Events::with(new Event(
                $id2,
                EventType::fromString('SomeOther'),
                EventData::fromString('a'),
            )),
            ExpectedVersion::fromVersion(Event\Version::fromInteger(20))
        );

        self::assertEquals(
            $expected,
            $actual
        );
    }

    public function test_list_same_stream_twice(): void
    {
        $commit = EventsForCommit::create(
            EventsForStreams::create(
                EventsForStream::create(
                    StreamName::fromString('stream-1'),
                    Events::with(new Event(
                        EventId::create(),
                        EventType::fromString('SomeEventType'),
                        EventData::fromString('a'),
                    )),
                ),
                EventsForStream::create(
                    StreamName::fromString('stream-2'),
                    Events::with(new Event(
                        EventId::create(),
                        EventType::fromString('SomeOther'),
                        EventData::fromString('a'),
                    )),
                ),
                EventsForStream::create(
                    StreamName::fromString('stream-1'),
                    Events::with(new Event(
                        EventId::create(),
                        EventType::fromString('SomeEventType'),
                        EventData::fromString('a'),
                    )),
                ),
            ),
            ExpectedVersionForStreams::create(
                ExpectedVersionForStream::create(
                    StreamName::fromString('stream-1'),
                    ExpectedVersion::NO_STREAM(),
                ),
                ExpectedVersionForStream::create(
                    StreamName::fromString('stream-2'),
                    ExpectedVersion::NO_STREAM(),
                )
            ),
        );

        self::assertSame(
            ['stream-1', 'stream-2', 'stream-1'],
            array_map(
                fn (EventsForStream $eventsForStream) => $eventsForStream->streamName->value,
                iterator_to_array($commit->eventsForStreams)
            )
        );

        self::assertSame(
            '[stream-1: -1 [no stream], stream-2: -1 [no stream]]',
            $commit->expectedVersionForStreams->toDebugString()
        );
    }
}
