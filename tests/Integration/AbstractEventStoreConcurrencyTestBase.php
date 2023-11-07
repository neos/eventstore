<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration;

use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Exception\ConcurrencyException;
use Neos\EventStore\Model\Event;
use Neos\EventStore\Model\Event\EventData;
use Neos\EventStore\Model\Event\EventId;
use Neos\EventStore\Model\Event\EventMetadata;
use Neos\EventStore\Model\Event\EventType;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Event\Version;
use Neos\EventStore\Model\EventEnvelope;
use Neos\EventStore\Model\Events;
use Neos\EventStore\Model\EventStream\ExpectedVersion;
use Neos\EventStore\Model\EventStream\MaybeVersion;
use Neos\EventStore\Model\EventStream\VirtualStreamName;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
abstract class AbstractEventStoreConcurrencyTestBase extends TestCase
{
    abstract public static function cleanup(): void;

    abstract protected static function createEventStore(string $id): EventStoreInterface;

    public static function commit_consistency_dataProvider(): iterable
    {
        for ($i = 0; $i < 40; $i++) {
            yield [$i];
        }
    }

    #[DataProvider('commit_consistency_dataProvider')]
    #[Group('parallel')]
    public function test_commit_consistency(int $process): void
    {
        $numberOfEventTypes = 5;
        $numberOfStreams = 3;
        $maxNumberOfEventsPerCommit = 3;
        $numberOfEventBatches = 30;

        $eventTypes = self::spawn($numberOfEventTypes, static fn (int $index) => EventType::fromString('Events' . $index));
        $streamNames = self::spawn($numberOfStreams, static fn (int $index) => StreamName::fromString('stream-' . $index));
        for ($eventBatch = 0; $eventBatch < $numberOfEventBatches; $eventBatch ++) {
            $streamName = self::either(...$streamNames);
            $streamVersion = $this->getStreamVersion('commit', $streamName);
            $expectedVersion = $streamVersion->isNothing() ? ExpectedVersion::NO_STREAM() : ExpectedVersion::fromVersion($streamVersion->unwrap());

            $numberOfEvents = self::between(1, $maxNumberOfEventsPerCommit);
            $events = [];
            for ($i = 0; $i < $numberOfEvents; $i++) {
                $descriptor = $process . '(' . getmypid() . ') ' . $eventBatch . '.' . ($i + 1) . '/' . $numberOfEvents;
                $eventData = $i > 0 ? ['descriptor' => $descriptor] : ['expectedVersion' => $expectedVersion->value, 'descriptor' => $descriptor];
                $events[] = new Event(EventId::create(), self::either(...$eventTypes), EventData::fromString(json_encode($eventData, JSON_THROW_ON_ERROR)), EventMetadata::none());
            }
            try {
                static::createEventStore('commit')->commit($streamName, Events::fromArray($events), $expectedVersion);
            } catch (ConcurrencyException $e) {
            }
        }
        self::assertTrue(true);
    }

    public static function validateEvents(): void
    {
        /** @var array<string, EventEnvelope[]> $processedEventEnvelopesByStreamName */
        $processedEventEnvelopesByStreamName = [];
        $lastSequenceNumber = 0;
        foreach (static::createEventStore('commit')->load(VirtualStreamName::all()) as $eventEnvelope) {
            $sequenceNumber = $eventEnvelope->sequenceNumber->value;
            self::assertGreaterThan($lastSequenceNumber, $sequenceNumber, sprintf('Expected sequence number of event "%s" to be greater than the previous one (%d) but it is %d', $eventEnvelope->event->id->value, $lastSequenceNumber, $sequenceNumber));
            $payload = json_decode($eventEnvelope->event->data->value, true, 512, JSON_THROW_ON_ERROR);
            if (!isset($processedEventEnvelopesByStreamName[$eventEnvelope->streamName->value])) {
                self::assertSame(ExpectedVersion::NO_STREAM()->value, $payload['expectedVersion'], sprintf('Event "%s" is the first in stream "%s" but it was committed with an "expectedVersion" of %d instead of %d', $eventEnvelope->event->id->value, $eventEnvelope->streamName->value, $payload['expectedVersion'], ExpectedVersion::NO_STREAM()->value));
                self::assertSame(Version::first()->value, $eventEnvelope->version->value, sprintf('Event "%s" is the first in stream "%s" but it has a version of %d instead of %d', $eventEnvelope->event->id->value, $eventEnvelope->streamName->value, $eventEnvelope->version->value, Version::first()->value));
                $processedEventEnvelopesByStreamName[$eventEnvelope->streamName->value] = [$eventEnvelope];
            } else {
                $numberOfEventsInStream = count($processedEventEnvelopesByStreamName[$eventEnvelope->streamName->value]);
                $expectedVersion = Version::fromInteger($numberOfEventsInStream);
                self::assertSame($expectedVersion->value, $eventEnvelope->version->value, sprintf('Event "%s" is the %d. in stream "%s" but it has a version of %d instead of %d', $eventEnvelope->event->id->value, $numberOfEventsInStream + 1, $eventEnvelope->streamName->value, $eventEnvelope->version->value, $expectedVersion->value));
                $processedEventEnvelopesByStreamName[$eventEnvelope->streamName->value][] = $eventEnvelope;
            }
            $lastSequenceNumber = $sequenceNumber;
        }
    }

    // ----------------------------------------------


    public function getStreamVersion(string $eventStoreId, StreamName $streamName): MaybeVersion
    {
        $lastEventEnvelope = null;
        foreach (static::createEventStore($eventStoreId)->load($streamName)->backwards() as $eventEnvelope) {
            $lastEventEnvelope = $eventEnvelope;
            break;
        }
        return MaybeVersion::fromVersionOrNull($lastEventEnvelope?->version);
    }

    private static function spawn(int $number, \Closure $closure): array
    {
        return array_map($closure, range(1, $number));
    }

    /**
     * @template T
     * @param T ...$choices
     * @return T
     */
    private static function either(...$choices): mixed
    {
        return $choices[array_rand($choices)];
    }

    /**
     * @template T
     * @param T ...$choices
     * @return array<T>
     */
    private static function some(int $max, ...$choices): array
    {
        $amount = self::between(1, min($max, count($choices)));
        shuffle($choices);
        return array_slice($choices, 0, $amount);
    }

    private static function between(int $min, int $max): int
    {
        return random_int($min, $max);
    }
}
