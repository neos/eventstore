<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Unit;

use Neos\EventStore\Model\Event;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\EventsForCommit;
use Neos\EventStore\Model\EventStream\ExpectedVersion;
use Neos\EventStore\Model\EventStream\ExpectedVersionForStream;
use Neos\EventStore\Model\EventStream\ExpectedVersionForStreams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventsForCommit::class)]
class ExpectedVersionForStreamTest extends TestCase
{
    public function test_illegal_empty_list(): void
    {
        $this->expectException(\TypeError::class);

        /** @phpstan-ignore-next-line */
        ExpectedVersionForStreams::create();
    }

    public function test_append(): void
    {
        $expected = ExpectedVersionForStreams::create(
            ExpectedVersionForStream::create(
                StreamName::fromString('stream-1'),
                ExpectedVersion::NO_STREAM(),
            ),
            ExpectedVersionForStream::create(
                StreamName::fromString('stream-2'),
                ExpectedVersion::fromVersion(Event\Version::fromInteger(20))
            )
        );

        $actual = ExpectedVersionForStreams::create(ExpectedVersionForStream::create(
            StreamName::fromString('stream-1'),
            ExpectedVersion::NO_STREAM(),
        ))->withAppended(
            ExpectedVersionForStream::create(
                StreamName::fromString('stream-2'),
                ExpectedVersion::fromVersion(Event\Version::fromInteger(20))
            )
        );

        self::assertEquals(
            $expected,
            $actual
        );
    }

    public function test_list_same_stream_twice_create(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate expected version 20 defined for stream stream-1');

        ExpectedVersionForStreams::create(
            ExpectedVersionForStream::create(
                StreamName::fromString('stream-1'),
                ExpectedVersion::NO_STREAM(),
            ),
            ExpectedVersionForStream::create(
                StreamName::fromString('stream-2'),
                ExpectedVersion::fromVersion(Event\Version::fromInteger(20))
            ),
            ExpectedVersionForStream::create(
                StreamName::fromString('stream-1'),
                ExpectedVersion::fromVersion(Event\Version::fromInteger(20))
            ),
        );
    }

    public function test_list_same_stream_twice_append(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate expected version -1 [no stream] defined for stream stream-1');

        $subject = ExpectedVersionForStreams::create(
            ExpectedVersionForStream::create(
                StreamName::fromString('stream-1'),
                ExpectedVersion::NO_STREAM(),
            ),
            ExpectedVersionForStream::create(
                StreamName::fromString('stream-2'),
                ExpectedVersion::fromVersion(Event\Version::fromInteger(20))
            )
        );

        $subject->withAppended(
            ExpectedVersionForStream::create(
                StreamName::fromString('stream-1'),
                ExpectedVersion::NO_STREAM()
            )
        );
    }
}
