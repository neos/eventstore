<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Unit;

use Neos\EventStore\Exception\DuplicateVersionConstraintException;
use Neos\EventStore\Model\Event;
use Neos\EventStore\Model\Event\EventData;
use Neos\EventStore\Model\Event\EventId;
use Neos\EventStore\Model\Event\EventType;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Events;
use Neos\EventStore\Model\EventsForCommit;
use Neos\EventStore\Model\EventsForStream;
use Neos\EventStore\Model\EventsForStreams;
use Neos\EventStore\Model\EventStream\ExpectedNoStream;
use Neos\EventStore\Model\EventStream\ExpectedStreamExists;
use Neos\EventStore\Model\EventStream\ExpectedVersion;
use Neos\EventStore\Model\EventStream\ExpectedVersionForStream;
use Neos\EventStore\Model\EventStream\ExpectedVersionForStreams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventsForCommit::class)]
class EventsForCommitTest extends TestCase
{
    public function test_illegal_empty_events_list(): void
    {
        $this->expectException(\TypeError::class);

        EventsForCommit::create(
            /** @phpstan-ignore-next-line */
            EventsForStreams::create(),
            ExpectedVersionForStreams::create(),
        );
    }

    public function test_no_stream_create(): void
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
                )
            ),
            ExpectedVersionForStreams::create(
                ExpectedNoStream::create(
                    StreamName::fromString('stream-1'),
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
        );

        self::assertEquals(
            $expected,
            $actual
        );
    }

    public function test_stream_exists_create(): void
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
                )
            ),
            ExpectedVersionForStreams::create(
                ExpectedStreamExists::create(
                    StreamName::fromString('stream-1'),
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
            ExpectedVersion::STREAM_EXISTS()
        );

        self::assertEquals(
            $expected,
            $actual
        );
    }

    public function test_stream_version_any_create(): void
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
                )
            ),
            ExpectedVersionForStreams::create(),
        );

        $actual = EventsForCommit::createEventsForStreamAndExpectedVersion(
            StreamName::fromString('stream-1'),
            Events::with(new Event(
                $id1,
                EventType::fromString('SomeEventType'),
                EventData::fromString('a'),
            )),
            ExpectedVersion::ANY()
        );

        self::assertEquals(
            $expected,
            $actual
        );
    }

    public function test_stream_version_equals_create(): void
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
                )
            ),
            ExpectedVersionForStreams::create(
                ExpectedVersionForStream::create(
                    StreamName::fromString('stream-1'),
                    Event\Version::fromInteger(12)
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
            ExpectedVersion::fromVersion(Event\Version::fromInteger(12))
        );

        self::assertEquals(
            $expected,
            $actual
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
                ExpectedNoStream::create(
                    StreamName::fromString('stream-1'),
                ),
                ExpectedVersionForStream::create(
                    StreamName::fromString('stream-2'),
                    Event\Version::fromInteger(20)
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

    public function test_append_same_strea(): void
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
                ExpectedNoStream::create(
                    StreamName::fromString('stream-1'),
                ),
                ExpectedNoStream::create(
                    StreamName::fromString('stream-2'),
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
            '[[no stream-1], [no stream-2]]',
            $commit->expectedVersionForStreams->toDebugString()
        );
    }

    public function test_append_same_stream_version_twice(): void
    {
        $this->expectException(DuplicateVersionConstraintException::class);
        $this->expectExceptionMessage('Duplicate constraint [no stream-1] and [stream-1 equals 1]');

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
            ),
            ExpectedVersionForStreams::create(
                ExpectedNoStream::create(
                    StreamName::fromString('stream-1'),
                ),
                ExpectedNoStream::create(
                    StreamName::fromString('stream-2'),
                )
            ),
        );

        $commit->withEventsForStreamAndExpectedVersion(
            StreamName::fromString('stream-1'),
            Events::with(new Event(
                EventId::create(),
                EventType::fromString('SomeEventType'),
                EventData::fromString('a'),
            )),
            ExpectedVersion::fromVersion(Event\Version::fromInteger(1))
        );
    }

    public function test_append_same_stream_version_and_any_version(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate constraint -2 [any] and [no stream-1]');

        $commit = EventsForCommit::create(
            EventsForStreams::create(
                EventsForStream::create(
                    StreamName::fromString('stream-1'),
                    Events::with(new Event(
                        EventId::create(),
                        EventType::fromString('SomeEventType'),
                        EventData::fromString('a'),
                    ))
                )
            ),
            ExpectedVersionForStreams::create(
                ExpectedNoStream::create(
                    StreamName::fromString('stream-1')
                )
            )
        );

        $commit->withEventsForStreamAndExpectedVersion(
            StreamName::fromString('stream-1'),
            Events::with(new Event(
                EventId::create(),
                EventType::fromString('SomeEventType'),
                EventData::fromString('a'),
            )),
            ExpectedVersion::ANY()
        );
    }

    /**
     * While {@see test_append_same_stream_version_and_any_version} is forbidden, it is not straight forward to forbid using ANY multiple times consistently for the same stream - and it does not harm.
     */
    public function test_append_same_stream_any_version_twice(): void
    {
        $commit = EventsForCommit::create(
            EventsForStreams::create(
                EventsForStream::create(
                    StreamName::fromString('stream-1'),
                    Events::with($firstEvent = new Event(
                        EventId::create(),
                        EventType::fromString('SomeEventType'),
                        EventData::fromString('a'),
                    )),
                )
            ),
            ExpectedVersionForStreams::create()
        );

        $commitViaAny = $commit->withEventsForStreamAndExpectedVersion(
            StreamName::fromString('stream-1'),
            Events::with($newEvent = new Event(
                EventId::create(),
                EventType::fromString('SomeEventType'),
                EventData::fromString('a'),
            )),
            // ANY is simply ignored here because there are no constraints set.
            ExpectedVersion::ANY()
        );

        self::assertEquals(
            EventsForCommit::create(
                EventsForStreams::create(
                    EventsForStream::create(
                        StreamName::fromString('stream-1'),
                        Events::with($firstEvent),
                    ),
                    EventsForStream::create(
                        StreamName::fromString('stream-1'),
                        Events::with($newEvent)
                    )
                ),
                ExpectedVersionForStreams::create()
            ),
            $commitViaAny
        );

        self::assertEquals(
            '[]',
            $commitViaAny->expectedVersionForStreams->toDebugString()
        );

        // The same
        $commitViaNull = $commit->withEventsForStream(
            StreamName::fromString('stream-1'),
            Events::with($newEvent),
        );

        self::assertEquals(
            $commitViaAny,
            $commitViaNull
        );
    }

    /**
     * While {@see test_append_same_stream_version_and_any_version} is forbidden, it is allowed to write further on the stream without any checks via {@see EventsForCommit::withEventsForStream}
     */
    public function test_append_same_stream_twice(): void
    {
        $commit = EventsForCommit::create(
            EventsForStreams::create(
                EventsForStream::create(
                    StreamName::fromString('stream-1'),
                    Events::with($firstEvent = new Event(
                        EventId::create(),
                        EventType::fromString('SomeEventType'),
                        EventData::fromString('a'),
                    )),
                )
            ),
            ExpectedVersionForStreams::create(
                ExpectedNoStream::create(
                    StreamName::fromString('stream-1')
                )
            )
        );

        // The same
        $commitActual = $commit->withEventsForStream(
            StreamName::fromString('stream-1'),
            Events::with($newEvent = new Event(
                EventId::create(),
                EventType::fromString('SomeEventType'),
                EventData::fromString('a'),
            )),
        );

        self::assertEquals(
            EventsForCommit::create(
                EventsForStreams::create(
                    EventsForStream::create(
                        StreamName::fromString('stream-1'),
                        Events::with($firstEvent),
                    ),
                    EventsForStream::create(
                        StreamName::fromString('stream-1'),
                        Events::with($newEvent)
                    )
                ),
                ExpectedVersionForStreams::create(
                    ExpectedNoStream::create(
                        StreamName::fromString('stream-1')
                    )
                )
            ),
            $commitActual
        );

        self::assertEquals(
            '[[no stream-1]]',
            $commitActual->expectedVersionForStreams->toDebugString()
        );
    }
}
