<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Unit\Helper;

use Neos\EventStore\Helper\ClosureEventStream;
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

#[CoversClass(ClosureEventStream::class)]
final class ClosureEventStreamTest extends TestCase
{
    public static function iteration_dataProvider(): \Generator
    {
        $mockEventStream = ClosureEventStream::create(static function(?SequenceNumber $minimumSequenceNumber, ?SequenceNumber $maximumSequenceNumber, ?int $limit, bool $backwards) {
            $result = '';
            $result .= $minimumSequenceNumber !== null ? $minimumSequenceNumber->value : '_';
            $result .= $maximumSequenceNumber !== null ? $maximumSequenceNumber->value : '_';
            $result .= $limit !== null ? (string)$limit : '_';
            $result .= $backwards ? 'b' : 'f';
            yield new EventEnvelope(
                new Event(
                    EventId::create(),
                    EventType::fromString('SomeEventType'),
                    EventData::fromString($result),
                ),
                StreamName::fromString('some-stream'),
                Version::fromInteger(1),
                SequenceNumber::fromInteger(1),
                new \DateTimeImmutable(),
            );
        });
        yield [$mockEventStream, '___f'];
        yield [$mockEventStream->limit(3), '__3f'];
        yield [$mockEventStream->withMinimumSequenceNumber(SequenceNumber::fromInteger(3)), '3__f'];
        yield [$mockEventStream->withMaximumSequenceNumber(SequenceNumber::fromInteger(3)), '_3_f'];
        yield [$mockEventStream->backwards(), '___b'];
        yield [$mockEventStream->withMinimumSequenceNumber(SequenceNumber::fromInteger(3))->withMaximumSequenceNumber(SequenceNumber::fromInteger(6)), '36_f'];
        yield [$mockEventStream->backwards()->withMinimumSequenceNumber(SequenceNumber::fromInteger(3))->withMaximumSequenceNumber(SequenceNumber::fromInteger(6)), '36_b'];
        yield [$mockEventStream->backwards()->withMinimumSequenceNumber(SequenceNumber::fromInteger(2))->withMaximumSequenceNumber(SequenceNumber::fromInteger(8))->limit(2), '282b'];
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
