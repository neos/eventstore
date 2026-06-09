<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Unit;

use Neos\EventStore\Model\Event;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\EventsForCommit;
use Neos\EventStore\Model\EventStream\ExpectedNoStream;
use Neos\EventStore\Model\EventStream\ExpectedVersionForStream;
use Neos\EventStore\Model\EventStream\ExpectedVersionForStreams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventsForCommit::class)]
class ExpectedVersionForStreamTest extends TestCase
{
    public function test_any_via_empty_list(): void
    {
        self::assertCount(
            0,
            ExpectedVersionForStreams::create()
        );
    }

    public function test_append(): void
    {
        $expected = ExpectedVersionForStreams::create(
            ExpectedNoStream::create(
                StreamName::fromString('stream-1'),
            ),
            ExpectedVersionForStream::create(
                StreamName::fromString('stream-2'),
                Event\Version::fromInteger(20)
            )
        );

        $actual = ExpectedVersionForStreams::create(
            ExpectedNoStream::create(
                StreamName::fromString('stream-1')
            )
        )->withAppended(
            ExpectedVersionForStream::create(
                StreamName::fromString('stream-2'),
                Event\Version::fromInteger(20)
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
        $this->expectExceptionMessage('Duplicate constraint [stream-1 equals 20]');

        ExpectedVersionForStreams::create(
            ExpectedNoStream::create(
                StreamName::fromString('stream-1'),
            ),
            ExpectedVersionForStream::create(
                StreamName::fromString('stream-2'),
                Event\Version::fromInteger(20)
            ),
            ExpectedVersionForStream::create(
                StreamName::fromString('stream-1'),
                Event\Version::fromInteger(20)
            ),
        );
    }

    public function test_list_same_stream_twice_append(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate constraint [no stream-1]');

        $subject = ExpectedVersionForStreams::create(
            ExpectedNoStream::create(
                StreamName::fromString('stream-1'),
            ),
            ExpectedVersionForStream::create(
                StreamName::fromString('stream-2'),
                Event\Version::fromInteger(20)
            )
        );

        $subject->withAppended(
            ExpectedNoStream::create(
                StreamName::fromString('stream-1'),
            )
        );
    }
}
