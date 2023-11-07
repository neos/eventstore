<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\CatchUp;


use Neos\EventStore\CatchUp\CatchUp;
use Neos\EventStore\CatchUp\CheckpointStorageInterface;
use Neos\EventStore\Helper\BatchEventStream;
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

#[CoversClass(CatchUp::class)]
final class CatchUpTest extends TestCase
{

    public function test_sequence_number_is_updated_to_last_iterated_event_with_default_batch_size(): void
    {
        $mockEventStream = self::mockEventStream(8);
        $eventHandler = fn () => null;
        $mockCheckpointStorage = $this->getMockBuilder(CheckpointStorageInterface::class)->getMock();
        $highestAppliedSequenceNumber = SequenceNumber::none();
        $mockCheckpointStorage->method('acquireLock')->willReturnCallback(function () use (&$highestAppliedSequenceNumber) {
            return $highestAppliedSequenceNumber;
        });
        $mockCheckpointStorage->expects(self::atLeastOnce())->method('updateAndReleaseLock')->willReturnCallback(function(SequenceNumber $sequenceNumber) use (&$highestAppliedSequenceNumber) {
            $highestAppliedSequenceNumber = $sequenceNumber;
        });

        CatchUp::create($eventHandler, $mockCheckpointStorage)
            ->run($mockEventStream);

        self::assertSame(8, $highestAppliedSequenceNumber->value);
    }

    public function test_sequence_number_is_updated_to_last_iterated_event_if_batch_size_is_larger_than_event_stream(): void
    {
        $mockEventStream = self::mockEventStream(8);
        $eventHandler = fn () => null;
        $mockCheckpointStorage = $this->getMockBuilder(CheckpointStorageInterface::class)->getMock();
        $highestAppliedSequenceNumber = SequenceNumber::none();
        $mockCheckpointStorage->method('acquireLock')->willReturnCallback(function () use (&$highestAppliedSequenceNumber) {
            return $highestAppliedSequenceNumber;
        });
        $mockCheckpointStorage->expects(self::atLeastOnce())->method('updateAndReleaseLock')->willReturnCallback(function(SequenceNumber $sequenceNumber) use (&$highestAppliedSequenceNumber) {
            $highestAppliedSequenceNumber = $sequenceNumber;
        });

        CatchUp::create($eventHandler, $mockCheckpointStorage)
            ->withBatchSize(10)
            ->run($mockEventStream);

        self::assertSame(8, $highestAppliedSequenceNumber->value);
    }

    private static function mockEventStream(int $numberOfEvents): EventStreamInterface
    {
        $mockEvents = [];
        $now = new \DateTimeImmutable();
        for ($index = 0; $index < $numberOfEvents; $index ++) {
            $char = chr($index + 65);
            $mockEvents[] = new EventEnvelope(
                new Event(
                    EventId::create(),
                    EventType::fromString('SomeEventType'),
                    EventData::fromString($char),
                    EventMetadata::none(),
                ),
                StreamName::fromString('some-stream'),
                Version::fromInteger($index),
                SequenceNumber::fromInteger($index + 1),
                $now,
            );
        }
        return InMemoryEventStream::create(...$mockEvents);
    }
}
