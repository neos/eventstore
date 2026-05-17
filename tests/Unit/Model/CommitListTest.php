<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Unit\Model;

use Neos\EventStore\Model\Commit;
use Neos\EventStore\Model\CommitList;
use Neos\EventStore\Model\Event;
use Neos\EventStore\Model\Event\EventData;
use Neos\EventStore\Model\Event\EventId;
use Neos\EventStore\Model\Event\EventType;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Events;
use Neos\EventStore\Model\EventStream\ExpectedVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommitList::class)]
class CommitListTest extends TestCase
{
    public function test_illegal_empty_list(): void
    {
        $this->expectException(\TypeError::class);

        /** @phpstan-ignore-next-line */
        CommitList::create();
    }

    public function test_list_same_stream_twice(): void
    {
        $commits = CommitList::create(
            new Commit(
                StreamName::fromString('stream-1'),
                Events::with(new Event(
                    EventId::create(),
                    EventType::fromString('SomeEventType'),
                    EventData::fromString('a'),
                )),
                ExpectedVersion::NO_STREAM(),
            ),
            new Commit(
                StreamName::fromString('stream-2'),
                Events::with(new Event(
                    EventId::create(),
                    EventType::fromString('SomeOther'),
                    EventData::fromString('a'),
                )),
                ExpectedVersion::STREAM_EXISTS()
            ),
            new Commit(
                StreamName::fromString('stream-1'),
                Events::with(new Event(
                    EventId::create(),
                    EventType::fromString('SomeEventType'),
                    EventData::fromString('a'),
                )),
                ExpectedVersion::STREAM_EXISTS()
            ),
        );

        self::assertSame(
            ['stream-1', 'stream-2', 'stream-1'],
            array_map(
                fn (Commit $commit) => $commit->streamName->value,
                iterator_to_array($commits)
            )
        );
    }
}
