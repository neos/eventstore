<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Unit\Helper;

use Neos\EventStore\Helper\InMemoryEventStream;
use Neos\EventStore\Model\Event;
use Neos\EventStore\Model\Event\EventData;
use Neos\EventStore\Model\Event\EventId;
use Neos\EventStore\Model\Event\EventMetadata;
use Neos\EventStore\Model\Event\EventType;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Event\Version;
use Neos\EventStore\Model\EventEnvelope;
use Neos\EventStore\Model\EventStream\EventStreamInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryEventStream::class)]
final class
InMemoryEventStreamTest extends TestCase
{

    public static function iteration_dataProvider(): \Generator
    {
        $mockEvents = [];
        $now = new \DateTimeImmutable();
        foreach (range('a', 'h') as $index => $char) {
            $mockEvents[] = new EventEnvelope(
                new Event(
                    EventId::create(),
                    EventType::fromString('SomeEventType'),
                    EventData::fromString($char),
                ),
                StreamName::fromString('some-stream'),
                Version::fromInteger($index),
                SequenceNumber::fromInteger($index + 1),
                $now,
            );
        }
        $mockEventStream = InMemoryEventStream::create(...$mockEvents);
        yield [$mockEventStream, 'abcdefgh'];
        yield [$mockEventStream->limit(3), 'abc'];
        yield [$mockEventStream->withMinimumSequenceNumber(SequenceNumber::fromInteger(3)), 'cdefgh'];
        yield [$mockEventStream->withMaximumSequenceNumber(SequenceNumber::fromInteger(3)), 'abc'];
        yield [$mockEventStream->backwards(), 'hgfedcba'];
        yield [$mockEventStream->withMinimumSequenceNumber(SequenceNumber::fromInteger(15)), ''];
        yield [$mockEventStream->withMinimumSequenceNumber(SequenceNumber::fromInteger(3))->withMaximumSequenceNumber(SequenceNumber::fromInteger(6)), 'cdef'];
        yield [$mockEventStream->withMinimumSequenceNumber(SequenceNumber::fromInteger(4))->withMaximumSequenceNumber(SequenceNumber::fromInteger(3)), ''];
        yield [$mockEventStream->backwards()->withMinimumSequenceNumber(SequenceNumber::fromInteger(3))->withMaximumSequenceNumber(SequenceNumber::fromInteger(6)), 'fedc'];
        yield [$mockEventStream->backwards()->withMinimumSequenceNumber(SequenceNumber::fromInteger(2))->withMaximumSequenceNumber(SequenceNumber::fromInteger(8))->limit(2), 'hg'];
    }

    /**
     * @dataProvider iteration_dataProvider
     */
    public function test_iteration(EventStreamInterface $eventStream, string $expectedResult): void
    {
        $actualResult = implode('', array_map(static fn (EventEnvelope $eventEnvelope) => $eventEnvelope->event->data->value, iterator_to_array($eventStream)));
        self::assertSame($expectedResult, $actualResult);
    }
}
