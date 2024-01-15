<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration;

use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Exception\ConcurrencyException;
use Neos\EventStore\Model\Event\EventTypes;
use Neos\EventStore\Model\EventStore\CommitResult;
use Neos\EventStore\Model\Event\EventData;
use Neos\EventStore\Model\Event\EventId;
use Neos\EventStore\Model\Event\EventMetadata;
use Neos\EventStore\Model\EventStream\EventStreamFilter;
use Neos\EventStore\Model\EventStream\EventStreamInterface;
use Neos\EventStore\Model\Event\EventType;
use Neos\EventStore\Model\EventStream\ExpectedVersion;
use Neos\EventStore\Model\EventEnvelope;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Event\Version;
use Neos\EventStore\Model\EventStream\MaybeVersion;
use Neos\EventStore\Model\EventStream\VirtualStreamName;
use Neos\EventStore\Model\Event;
use Neos\EventStore\Model\Events;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
abstract class AbstractEventStoreTestBase extends TestCase
{
    private ?EventStoreInterface $eventStore = null;

    abstract protected static function createEventStore(): EventStoreInterface;

    abstract protected static function resetEventStore(): void;

    // --- Tests ----

    public function test_commit_increases_version_per_stream(): void
    {
        $this->commitDummyEvents();
        self::assertEventStream($this->getEventStore()->load(VirtualStreamName::all()), [
            ['version' => 0],
            ['version' => 1],
            ['version' => 2],
            ['version' => 0],
            ['version' => 1],
            ['version' => 2],
        ]);
    }

    public function test_commit_increases_sequenceNumber(): void
    {
        $this->commitDummyEvents();

        self::assertEventStream($this->getEventStore()->load(VirtualStreamName::all()), [
            ['sequenceNumber' => 1],
            ['sequenceNumber' => 2],
            ['sequenceNumber' => 3],
            ['sequenceNumber' => 4],
            ['sequenceNumber' => 5],
            ['sequenceNumber' => 6],
        ]);
    }

    public static function dataProvider_commit_expectVersion_concurrencyException(): \Generator
    {
        yield ['streamName' => 'nonexisting-stream', ExpectedVersion::STREAM_EXISTS()];
        yield ['streamName' => 'nonexisting-stream', ExpectedVersion::fromVersion(Version::first())];
        yield ['streamName' => 'nonexisting-stream', ExpectedVersion::fromVersion(Version::fromInteger(123))];
        yield ['streamName' => 'existing-stream', ExpectedVersion::NO_STREAM()];
        yield ['streamName' => 'existing-stream', ExpectedVersion::fromVersion(Version::first())];
        yield ['streamName' => 'existing-stream', ExpectedVersion::fromVersion(Version::fromInteger(123))];
    }

    /**
     * @dataProvider dataProvider_commit_expectVersion_concurrencyException
     */
    public function test_commit_expectVersion_concurrencyExceptions(string $streamName, ExpectedVersion $expectedVersion): void
    {
        $this->commitEvents(array_map(static fn ($char) => ['data' => $char], range('a', 'c')), 'existing-stream');

        $this->expectException(ConcurrencyException::class);
        $this->commitEvent(['data' => 'something'], $streamName, $expectedVersion);
    }

    public static function dataProvider_commit_expectVersion_success(): \Generator
    {
        yield ['streamName' => 'nonexisting-stream', ExpectedVersion::ANY()];
        yield ['streamName' => 'nonexisting-stream', ExpectedVersion::NO_STREAM()];
        yield ['streamName' => 'existing-stream', ExpectedVersion::ANY()];
        yield ['streamName' => 'existing-stream', ExpectedVersion::STREAM_EXISTS()];
        yield ['streamName' => 'existing-stream', ExpectedVersion::fromVersion(Version::fromInteger(2))];
    }

    /**
     * @dataProvider dataProvider_commit_expectVersion_success
     */
    public function test_commit_expectVersion_success(string $streamName, ExpectedVersion $expectedVersion): void
    {
        $this->commitEvents(array_map(static fn ($char) => ['data' => $char], range('a', 'c')), 'existing-stream');
        $this->commitEvent(['data' => 'something'], $streamName, $expectedVersion);
        $this->expectNotToPerformAssertions();
    }

    public function test_commit_commitResult_contains_correct_highestCommittedSequenceNumber(): void
    {
        $commitResult = $this->commitEvents(array_map(static fn ($char) => ['data' => $char], range('a', 'c')), 'first-stream');
        self::assertSame(3, $commitResult->highestCommittedSequenceNumber->value);
    }

    public function test_commit_commitResult_contains_correct_highestCommittedVersion(): void
    {
        $commitResult = $this->commitEvents(array_map(static fn ($char) => ['data' => $char], range('a', 'c')), 'first-stream');
        self::assertSame(2, $commitResult->highestCommittedVersion->value);
    }

    public function test_load_returns_all_events(): void
    {
        $this->commitDummyEvents();

        self::assertEventStream($this->getEventStore()->load(VirtualStreamName::all()), [
            ['sequenceNumber' => 1, 'type' => 'SomeEventType', 'data' => 'a', 'metadata' => [], 'streamName' => 'first-stream', 'version' => 0],
            ['sequenceNumber' => 2, 'type' => 'SomeOtherEventType', 'data' => 'b', 'metadata' => [], 'streamName' => 'first-stream', 'version' => 1],
            ['sequenceNumber' => 3, 'type' => 'SomeEventType', 'data' => 'c', 'metadata' => [], 'streamName' => 'first-stream', 'version' => 2],
            ['sequenceNumber' => 4, 'type' => 'SomeOtherEventType', 'data' => 'd', 'metadata' => [], 'streamName' => 'second-stream', 'version' => 0],
            ['sequenceNumber' => 5, 'type' => 'SomeEventType', 'data' => 'e', 'metadata' => [], 'streamName' => 'second-stream', 'version' => 1],
            ['sequenceNumber' => 6, 'type' => 'SomeOtherEventType', 'data' => 'f', 'metadata' => [], 'streamName' => 'second-stream', 'version' => 2],
        ]);
    }

    public function test_load_returns_empty_stream_if_specified_streamName_does_not_exist(): void
    {
        $this->commitDummyEvents();

        self::assertEventStream($this->getEventStore()->load(StreamName::fromString('non-existing')), []);
    }

    public function test_load_returns_filtered_events_matching_specified_streamName(): void
    {
        $this->commitDummyEvents();

        self::assertEventStream($this->getEventStore()->load(StreamName::fromString('second-stream')), [
            ['sequenceNumber' => 4, 'type' => 'SomeOtherEventType', 'data' => 'd', 'metadata' => [], 'streamName' => 'second-stream', 'version' => 0],
            ['sequenceNumber' => 5, 'type' => 'SomeEventType', 'data' => 'e', 'metadata' => [], 'streamName' => 'second-stream', 'version' => 1],
            ['sequenceNumber' => 6, 'type' => 'SomeOtherEventType', 'data' => 'f', 'metadata' => [], 'streamName' => 'second-stream', 'version' => 2],
        ]);
    }

    public function test_load_returns_empty_stream_if_specified_eventType_does_not_exist(): void
    {
        $this->commitDummyEvents();

        self::assertEventStream($this->getEventStore()->load(VirtualStreamName::all(), EventStreamFilter::create(eventTypes: EventTypes::create(EventType::fromString('NonExistingEventType')))), []);
    }

    public function test_load_returns_filtered_events_matching_specified_evenTypes(): void
    {
        $this->commitDummyEvents();

        self::assertEventStream($this->getEventStore()->load(VirtualStreamName::all(), EventStreamFilter::create(eventTypes: EventTypes::create(EventType::fromString('SomeOtherEventType')))), [
            ['sequenceNumber' => 2, 'type' => 'SomeOtherEventType', 'data' => 'b', 'metadata' => [], 'streamName' => 'first-stream', 'version' => 1],
            ['sequenceNumber' => 4, 'type' => 'SomeOtherEventType', 'data' => 'd', 'metadata' => [], 'streamName' => 'second-stream', 'version' => 0],
            ['sequenceNumber' => 6, 'type' => 'SomeOtherEventType', 'data' => 'f', 'metadata' => [], 'streamName' => 'second-stream', 'version' => 2],
        ]);
    }

    public function test_deleteStream_does_not_reset_sequenceNumber(): void
    {
        $this->commitEvents(array_map(static fn ($char) => ['data' => $char], range('a', 'c')), 'first-stream');
        $this->deleteStream('first-stream');
        $this->commitEvents(array_map(static fn ($char) => ['data' => $char], range('d', 'f')), 'second-stream');

        self::assertEventStream($this->getEventStore()->load(VirtualStreamName::all()), [
            ['sequenceNumber' => 4],
            ['sequenceNumber' => 5],
            ['sequenceNumber' => 6],
        ]);
    }

    // --- Consistency tests -----

    public static function consistency_prepare(): void
    {
        static::createEventStore()->setup();
    }


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
            $streamVersion = $this->getStreamVersion($streamName);
            $expectedVersion = $streamVersion->isNothing() ? ExpectedVersion::NO_STREAM() : ExpectedVersion::fromVersion($streamVersion->unwrap());

            $numberOfEvents = self::between(1, $maxNumberOfEventsPerCommit);
            $events = [];
            for ($i = 0; $i < $numberOfEvents; $i++) {
                $descriptor = $process . '(' . getmypid() . ') ' . $eventBatch . '.' . ($i + 1) . '/' . $numberOfEvents;
                $eventData = $i > 0 ? ['descriptor' => $descriptor] : ['expectedVersion' => $expectedVersion->value, 'descriptor' => $descriptor];
                $events[] = new Event(EventId::create(), self::either(...$eventTypes), EventData::fromString(json_encode($eventData, JSON_THROW_ON_ERROR)), EventMetadata::none());
            }
            try {
                static::createEventStore()->commit($streamName, Events::fromArray($events), $expectedVersion);
            } catch (ConcurrencyException $e) {
            } catch (\Exception $e) {
                echo get_debug_type($e);
                exit;
            }
        }
        self::assertTrue(true);
    }

    public static function consistency_validateEvents(): void
    {
        /** @var array<string, EventEnvelope[]> $processedEventEnvelopesByStreamName */
        $processedEventEnvelopesByStreamName = [];
        $lastSequenceNumber = 0;
        foreach (static::createEventStore()->load(VirtualStreamName::all()) as $eventEnvelope) {
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


    public function tearDown(): void
    {
        static::resetEventStore();
    }


    // --- Helper methods -----

    /**
     * @param array<array{id?: string, type?: string, data?: string, metadata?: array<mixed>}> $events
     * @param string $streamName
     * @param ExpectedVersion|null $expectedVersion
     * @return CommitResult
     */
    final protected function commitEvents(array $events, string $streamName = 'some-stream', ExpectedVersion $expectedVersion = null): CommitResult
    {
        return $this->getEventStore()->commit(StreamName::fromString($streamName), Events::fromArray(array_map($this->convertEvent(...), $events)), $expectedVersion ?? ExpectedVersion::ANY());
    }

    /**
     * @param array{id?: string, type?: string, data?: string, metadata?: array<mixed>} $event
     * @param string $streamName
     * @param ExpectedVersion|null $expectedVersion
     * @return CommitResult
     */
    final protected function commitEvent(array $event, string $streamName = 'some-stream', ExpectedVersion $expectedVersion = null): CommitResult
    {
        return $this->commitEvents([$event], $streamName, $expectedVersion);
    }

    final protected function deleteStream(string $streamName): void
    {
        $this->getEventStore()->deleteStream(StreamName::fromString($streamName));
    }

    /**
     * @param EventStreamInterface $eventStream
     * @param array<array{id?: string, type?: string, data?: string, metadata?: array<mixed>, streamName?: string, version?: int, sequenceNumber?: int, recordedAt?: \DateTimeInterface}> $expectedEvents
     */
    final protected static function assertEventStream(EventStreamInterface $eventStream, array $expectedEvents): void
    {
        $actualEvents = [];
        $index = 0;
        foreach ($eventStream as $eventEnvelope) {
            $actualEvents[] = self::eventEnvelopeToArray(isset($expectedEvents[$index]) ? array_keys($expectedEvents[$index]) : ['id', 'type', 'data', 'metadata', 'streamName', 'version', 'sequenceNumber', 'recordedAt'], $eventEnvelope);
            $index ++;
        }
        self::assertEquals($expectedEvents, $actualEvents);
    }

    final protected function commitDummyEvents(): void
    {
        $typeClosure = static fn (string $char) => in_array($char, ['a', 'c', 'e'], true) ? 'SomeEventType' : 'SomeOtherEventType';
        $this->commitEvents(array_map(static fn ($char) => ['data' => $char, 'type' => $typeClosure($char)], range('a', 'c')), 'first-stream');
        $this->commitEvents(array_map(static fn ($char) => ['data' => $char, 'type' => $typeClosure($char)], range('d', 'f')), 'second-stream');
    }

    // --- Internal -----

    private function getEventStore(): EventStoreInterface
    {
        if ($this->eventStore === null) {
            $this->eventStore = static::createEventStore();
            $this->eventStore->setup();
        }
        return $this->eventStore;
    }

    /**
     * @param string[] $keys
     * @param EventEnvelope $eventEnvelope
     * @return array{id?: string, type?: string, data?: string, metadata?: array<mixed>, streamName?: string, version?: int, sequenceNumber?: int, recordedAt?: \DateTimeInterface}
     */
    private static function eventEnvelopeToArray(array $keys, EventEnvelope $eventEnvelope): array
    {
        $supportedKeys = ['id', 'type', 'data', 'metadata', 'streamName', 'version', 'sequenceNumber', 'recordedAt'];
        $unsupportedKeys = array_diff($keys, $supportedKeys);
        if ($unsupportedKeys !== []) {
            throw new \InvalidArgumentException(sprintf('Invalid key(s) "%s" for expected event. Allowed keys are: "%s"', implode('", "', $unsupportedKeys), implode('", "', $supportedKeys)), 1651755700);
        }
        $actualAsArray = [
            'id' => $eventEnvelope->event->id->value,
            'type' => $eventEnvelope->event->type->value,
            'data' => $eventEnvelope->event->data->value,
            'metadata' => $eventEnvelope->event->metadata->value,
            'streamName' => $eventEnvelope->streamName->value,
            'version' => $eventEnvelope->version->value,
            'sequenceNumber' => $eventEnvelope->sequenceNumber->value,
            'recordedAt' => $eventEnvelope->recordedAt,
        ];
        foreach (array_diff($supportedKeys, $keys) as $unusedKey) {
            unset($actualAsArray[$unusedKey]);
        }
        return $actualAsArray;
    }

    /**
     * @param array{id?: string, type?: string, data?: string, metadata?: array<mixed>} $event
     * @return Event
     */
    private function convertEvent(array $event): Event
    {
        return new Event(
            isset($event['id']) ? EventId::fromString($event['id']) : EventId::create(),
            EventType::fromString($event['type'] ?? 'SomeEventType'),
            EventData::fromString($event['data'] ?? ''),
            isset($event['metadata']) ? EventMetadata::fromArray($event['metadata']) : EventMetadata::none(),
        );
    }

    public function getStreamVersion(StreamName $streamName): MaybeVersion
    {
        $lastEventEnvelope = null;
        foreach (static::createEventStore()->load($streamName)->backwards() as $eventEnvelope) {
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
