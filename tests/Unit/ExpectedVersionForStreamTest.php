<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Unit;

use Neos\EventStore\Exception\DuplicateVersionConstraintException;
use Neos\EventStore\Model\Event;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\EventsForCommit;
use Neos\EventStore\Model\EventStream\ExpectedNoStream;
use Neos\EventStore\Model\EventStream\ExpectedStreamVersion;
use Neos\EventStore\Model\EventStream\ExpectedStreamConstraints;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventsForCommit::class)]
class ExpectedVersionForStreamTest extends TestCase
{
    public function test_any_via_empty_list(): void
    {
        self::assertCount(
            0,
            ExpectedStreamConstraints::create()
        );
    }

    public function test_append(): void
    {
        $expected = ExpectedStreamConstraints::create(
            ExpectedNoStream::create(
                StreamName::fromString('stream-1'),
            ),
            ExpectedStreamVersion::create(
                StreamName::fromString('stream-2'),
                Event\Version::fromInteger(20)
            )
        );

        $actual = ExpectedStreamConstraints::create(
            ExpectedNoStream::create(
                StreamName::fromString('stream-1')
            )
        )->withAppended(
            ExpectedStreamVersion::create(
                StreamName::fromString('stream-2'),
                Event\Version::fromInteger(20)
            )
        );

        self::assertEquals(
            $expected,
            $actual
        );
    }

    public function test_merge(): void
    {
        $expected = ExpectedStreamConstraints::create(
            ExpectedNoStream::create(
                StreamName::fromString('stream-1'),
            ),
            ExpectedStreamVersion::create(
                StreamName::fromString('stream-2'),
                Event\Version::fromInteger(20)
            ),
            ExpectedStreamVersion::create(
                StreamName::fromString('stream-3'),
                Event\Version::fromInteger(1)
            ),
        );

        $actual = ExpectedStreamConstraints::create(
            ExpectedNoStream::create(
                StreamName::fromString('stream-1')
            )
        )->merge(
            ExpectedStreamConstraints::create(
                ExpectedStreamVersion::create(
                    StreamName::fromString('stream-2'),
                    Event\Version::fromInteger(20)
                ),
                ExpectedStreamVersion::create(
                    StreamName::fromString('stream-3'),
                    Event\Version::fromInteger(1)
                ),
            )
        );

        self::assertEquals(
            $expected,
            $actual
        );
    }

    public function test_list_same_stream_twice_create(): void
    {
        $this->expectException(DuplicateVersionConstraintException::class);
        $this->expectExceptionMessage('Duplicate constraint [no stream-1] and [stream-1 equals 20]');

        ExpectedStreamConstraints::create(
            ExpectedNoStream::create(
                StreamName::fromString('stream-1'),
            ),
            ExpectedStreamVersion::create(
                StreamName::fromString('stream-2'),
                Event\Version::fromInteger(20)
            ),
            ExpectedStreamVersion::create(
                StreamName::fromString('stream-1'),
                Event\Version::fromInteger(20)
            ),
        );
    }

    public function test_list_same_stream_twice_append(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate constraint [no stream-1] and [no stream-1]');

        $subject = ExpectedStreamConstraints::create(
            ExpectedNoStream::create(
                StreamName::fromString('stream-1'),
            ),
            ExpectedStreamVersion::create(
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

    public function test_list_same_stream_twice_merge(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate constraint [no stream-1] and [no stream-1]');

        $subject = ExpectedStreamConstraints::create(
            ExpectedNoStream::create(
                StreamName::fromString('stream-1'),
            ),
            ExpectedStreamVersion::create(
                StreamName::fromString('stream-2'),
                Event\Version::fromInteger(20)
            )
        );

        $subject->merge(
            ExpectedStreamConstraints::create(
                ExpectedNoStream::create(
                    StreamName::fromString('stream-other'),
                ),
                ExpectedNoStream::create(
                    StreamName::fromString('stream-1'),
                ),
                ExpectedNoStream::create(
                    StreamName::fromString('stream-other-2'),
                )
            )
        );
    }

    public function test_list_multiple_streams_twice_merge(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate constraint [stream-2 equals 20] and [no stream-2]');

        $subject = ExpectedStreamConstraints::create(
            ExpectedStreamVersion::create(
                StreamName::fromString('stream-2'),
                Event\Version::fromInteger(20)
            ),
            ExpectedNoStream::create(
                StreamName::fromString('stream-1'),
            ),
        );

        $subject->merge(
            ExpectedStreamConstraints::create(
                ExpectedNoStream::create(
                    StreamName::fromString('stream-1'),
                ),
                ExpectedNoStream::create(
                    StreamName::fromString('stream-2'),
                )
            )
        );
    }
}
