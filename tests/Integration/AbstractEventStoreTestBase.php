<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration;

use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Exception\ConcurrencyException;
use Neos\EventStore\Model\Event;
use Neos\EventStore\Model\Event\CausationId;
use Neos\EventStore\Model\Event\EventData;
use Neos\EventStore\Model\Event\EventId;
use Neos\EventStore\Model\Event\EventMetadata;
use Neos\EventStore\Model\Event\EventType;
use Neos\EventStore\Model\Event\EventTypes;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Event\Version;
use Neos\EventStore\Model\EventEnvelope;
use Neos\EventStore\Model\Events;
use Neos\EventStore\Model\EventsForCommit;
use Neos\EventStore\Model\EventStore\CommitResult;
use Neos\EventStore\Model\EventStore\VersionForStream;
use Neos\EventStore\Model\EventStream\EventStreamFilter;
use Neos\EventStore\Model\EventStream\EventStreamInterface;
use Neos\EventStore\Model\EventStream\ExpectedVersion;
use Neos\EventStore\Model\EventStream\VirtualStreamName;
use Neos\EventStore\WithResetInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
abstract class AbstractEventStoreTestBase extends TestCase
{
    private ?EventStoreInterface $eventStore = null;

    /**
     * Must use {@see EventStoreFakeClock} for testing.
     */
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

    public function test_commitAll_increases_sequenceNumber_and_version_per_stream(): void
    {
        $commitResult = $this->getEventStore()->commitAll(
            EventsForCommit::createEventsForStreamAndExpectedVersion(
                StreamName::fromString('stream-1'),
                Events::fromArray(array_map(
                    fn (string $char) => $this->convertEvent(['data' => $char]),
                    range('a', 'c')
                )),
                ExpectedVersion::ANY()
            )->withEventsForStreamAndExpectedVersion(
                StreamName::fromString('stream-2'),
                Events::fromArray(array_map(
                    fn (string $char) => $this->convertEvent(['data' => $char]),
                    range('d', 'f')
                )),
                ExpectedVersion::ANY()
            )
        );
        self::assertEventStream($this->getEventStore()->load(VirtualStreamName::all()), [
            ['streamName' => 'stream-1', 'sequenceNumber' => 1, 'version' => 0],
            ['streamName' => 'stream-1', 'sequenceNumber' => 2, 'version' => 1],
            ['streamName' => 'stream-1', 'sequenceNumber' => 3, 'version' => 2],
            ['streamName' => 'stream-2', 'sequenceNumber' => 4, 'version' => 0],
            ['streamName' => 'stream-2', 'sequenceNumber' => 5, 'version' => 1],
            ['streamName' => 'stream-2', 'sequenceNumber' => 6, 'version' => 2],
        ]);

        self::assertSame(6, $commitResult->highestCommittedSequenceNumber->value);
        self::assertEquals([
            VersionForStream::create(StreamName::fromString('stream-1'), Version::fromInteger(2)),
            VersionForStream::create(StreamName::fromString('stream-2'), Version::fromInteger(2)),
        ], iterator_to_array($commitResult->versionForStreams));
    }

    public static function dataProvider_commit_expectVersion_concurrencyException(): \Generator
    {
        yield ['nonexisting-stream', ExpectedVersion::STREAM_EXISTS()];
        yield ['nonexisting-stream', ExpectedVersion::fromVersion(Version::first())];
        yield ['nonexisting-stream', ExpectedVersion::fromVersion(Version::fromInteger(123))];
        yield ['existing-stream', ExpectedVersion::NO_STREAM()];
        yield ['existing-stream', ExpectedVersion::fromVersion(Version::first())];
        yield ['existing-stream', ExpectedVersion::fromVersion(Version::fromInteger(123))];
    }

    /**
     * @dataProvider dataProvider_commit_expectVersion_concurrencyException
     */
    public function test_commit_expectVersion_concurrencyExceptions(string $streamName, ExpectedVersion $expectedVersion): void
    {
        $this->commitEvents(array_map(static fn ($char) => ['data' => $char], range('a', 'c')), 'existing-stream');

        try {
            $this->commitEvent(['data' => 'something'], $streamName, $expectedVersion);
        } catch (ConcurrencyException) {
            self::assertEventStream($this->getEventStore()->load(VirtualStreamName::all()), [
                ['streamName' => 'existing-stream', 'sequenceNumber' => 1],
                ['streamName' => 'existing-stream', 'sequenceNumber' => 2],
                ['streamName' => 'existing-stream', 'sequenceNumber' => 3],
            ]);
            return;
        }

        self::fail('No ConcurrencyException thrown, concurrent events commited.');
    }

    /**
     * @dataProvider dataProvider_commit_expectVersion_concurrencyException
     */
    public function test_commit_all_expectVersion_concurrencyExceptions(string $streamName, ExpectedVersion $expectedVersion): void
    {
        $this->commitEvents(array_map(static fn ($char) => ['data' => $char], range('a', 'c')), 'existing-stream');

        $commit = EventsForCommit::createEventsForStreamAndExpectedVersion(
            StreamName::fromString('other-stream'),
            Events::fromArray(array_map(
                fn (string $char) => $this->convertEvent(['data' => $char]),
                range('d', 'f')
            )), ExpectedVersion::ANY()
        );

        $concurrentCommit = $commit->withEventsForStreamAndExpectedVersion(
            StreamName::fromString($streamName),
            Events::with(
                $this->convertEvent(['data' => 'something'])
            ),
            $expectedVersion
        );

        try {
            $this->getEventStore()->commitAll($concurrentCommit);
        } catch (ConcurrencyException) {
            self::assertEventStream($this->getEventStore()->load(VirtualStreamName::all()), [
                ['streamName' => 'existing-stream', 'sequenceNumber' => 1],
                ['streamName' => 'existing-stream', 'sequenceNumber' => 2],
                ['streamName' => 'existing-stream', 'sequenceNumber' => 3],
            ]);
            return;
        }

        self::fail('No ConcurrencyException thrown, concurrent events commited.');
    }

    public function test_commitAll_expectVersion_concurrencyExceptions_same_stream(): void
    {
        $this->commitEvents(array_map(static fn ($char) => ['data' => $char], range('a', 'c')), 'unrelated-stream');

        $concurrentCommit = EventsForCommit::createEventsForStreamAndExpectedVersion(
            StreamName::fromString('stream-1'),
            Events::fromArray(array_map(
                fn (string $char) => $this->convertEvent(['data' => $char]),
                range('a', 'c')
            )),
            ExpectedVersion::NO_STREAM()
        )->withExpectedVersionForStream(
            StreamName::fromString('unrelated-stream'),
            ExpectedVersion::fromVersion(Version::fromInteger(1))
        );

        try {
            $this->getEventStore()->commitAll($concurrentCommit);
        } catch (ConcurrencyException) {
            self::assertEventStream($this->getEventStore()->load(VirtualStreamName::all()), [
                ['streamName' => 'unrelated-stream', 'sequenceNumber' => 1],
                ['streamName' => 'unrelated-stream', 'sequenceNumber' => 2],
                ['streamName' => 'unrelated-stream', 'sequenceNumber' => 3],
            ]);
            return;
        }

        self::fail('No ConcurrencyException thrown, concurrent events commited.');
    }

    public static function dataProvider_commit_expectVersion_success(): \Generator
    {
        yield ['nonexisting-stream', ExpectedVersion::ANY()];
        yield ['nonexisting-stream', ExpectedVersion::NO_STREAM()];
        yield ['existing-stream', ExpectedVersion::ANY()];
        yield ['existing-stream', ExpectedVersion::STREAM_EXISTS()];
        yield ['existing-stream', ExpectedVersion::fromVersion(Version::fromInteger(2))];
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

    /**
     * @dataProvider dataProvider_commit_expectVersion_success
     */
    public function test_commitAll_expectVersion_success(string $streamName, ExpectedVersion $expectedVersion): void
    {
        $this->commitEvents(array_map(static fn ($char) => ['data' => $char], range('a', 'c')), 'existing-stream');

        $commit = EventsForCommit::createEventsForStreamAndExpectedVersion(
            StreamName::fromString('other-stream'),
            Events::fromArray(array_map(
                fn (string $char) => $this->convertEvent(['data' => $char]),
                range('d', 'f')
            )),
            ExpectedVersion::ANY()
        );

        $commitResult = $this->getEventStore()->commitAll(
            $commit->withEventsForStreamAndExpectedVersion(
                StreamName::fromString($streamName),
                Events::with($this->convertEvent(['data' => 'something'])),
                $expectedVersion
            )
        );

        self::assertEventStream($this->getEventStore()->load(VirtualStreamName::all()), [
            ['streamName' => 'existing-stream', 'sequenceNumber' => 1],
            ['streamName' => 'existing-stream', 'sequenceNumber' => 2],
            ['streamName' => 'existing-stream', 'sequenceNumber' => 3],
            ['streamName' => 'other-stream', 'sequenceNumber' => 4],
            ['streamName' => 'other-stream', 'sequenceNumber' => 5],
            ['streamName' => 'other-stream', 'sequenceNumber' => 6],
            ['streamName' => $streamName, 'sequenceNumber' => 7],
        ]);

        self::assertSame(7, $commitResult->highestCommittedSequenceNumber->value);
    }

    public function test_commitAll_expectVersion_same_stream_segmented_success(): void
    {
        $commitResult = $this->getEventStore()->commitAll(
            EventsForCommit::createEventsForStreamAndExpectedVersion(
                StreamName::fromString('stream-1'),
                Events::fromArray(array_map(
                    fn (string $char) => $this->convertEvent(['data' => $char]),
                    range('a', 'c')
                )),
                ExpectedVersion::NO_STREAM()
            )->withEventsForStream(
                StreamName::fromString('stream-1'),
                Events::fromArray(array_map(
                    fn (string $char) => $this->convertEvent(['data' => $char]),
                    range('d', 'f')
                )),
            )
        );
        self::assertEventStream($this->getEventStore()->load(VirtualStreamName::all()), [
            ['streamName' => 'stream-1', 'sequenceNumber' => 1, 'version' => 0],
            ['streamName' => 'stream-1', 'sequenceNumber' => 2, 'version' => 1],
            ['streamName' => 'stream-1', 'sequenceNumber' => 3, 'version' => 2],
            ['streamName' => 'stream-1', 'sequenceNumber' => 4, 'version' => 3],
            ['streamName' => 'stream-1', 'sequenceNumber' => 5, 'version' => 4],
            ['streamName' => 'stream-1', 'sequenceNumber' => 6, 'version' => 5],
        ]);
        self::assertSame(6, $commitResult->highestCommittedSequenceNumber->value);
        self::assertEquals([
            VersionForStream::create(StreamName::fromString('stream-1'), Version::fromInteger(5)),
        ], iterator_to_array($commitResult->versionForStreams));
    }


    public function test_commitAll_expectVersion_same_stream_multiple_segmented_success(): void
    {
        $commitResult = $this->getEventStore()->commitAll(
            EventsForCommit::createEventsForStreamAndExpectedVersion(
                StreamName::fromString('stream-1'),
                Events::fromArray(array_map(
                    fn (string $char) => $this->convertEvent(['data' => $char]),
                    range('a', 'c')
                )),
                ExpectedVersion::NO_STREAM()
            )->withEventsForStreamAndExpectedVersion(
                StreamName::fromString('stream-2'),
                Events::with(
                    $this->convertEvent(['data' => 'x'])
                ),
                ExpectedVersion::NO_STREAM()
            )->withEventsForStream(
                StreamName::fromString('stream-1'),
                Events::fromArray(array_map(
                    fn (string $char) => $this->convertEvent(['data' => $char]),
                    range('d', 'f')
                )),
            )->withEventsForStream(
                StreamName::fromString('stream-1'),
                Events::with(
                    $this->convertEvent(['data' => 'g'])
                ),
            )
        );
        self::assertEventStream($this->getEventStore()->load(VirtualStreamName::all()), [
            ['streamName' => 'stream-1', 'sequenceNumber' => 1, 'version' => 0],
            ['streamName' => 'stream-1', 'sequenceNumber' => 2, 'version' => 1],
            ['streamName' => 'stream-1', 'sequenceNumber' => 3, 'version' => 2],
            ['streamName' => 'stream-2', 'sequenceNumber' => 4, 'version' => 0],
            ['streamName' => 'stream-1', 'sequenceNumber' => 5, 'version' => 3],
            ['streamName' => 'stream-1', 'sequenceNumber' => 6, 'version' => 4],
            ['streamName' => 'stream-1', 'sequenceNumber' => 7, 'version' => 5],
            ['streamName' => 'stream-1', 'sequenceNumber' => 8, 'version' => 6],
        ]);
        self::assertSame(8, $commitResult->highestCommittedSequenceNumber->value);
        self::assertEquals([
            VersionForStream::create(StreamName::fromString('stream-1'), Version::fromInteger(6)),
            VersionForStream::create(StreamName::fromString('stream-2'), Version::fromInteger(0)),
        ], iterator_to_array($commitResult->versionForStreams));
    }

    public function test_commitAll_expectVersion_success_unrelated_stream(): void
    {
        $this->commitEvents(array_map(static fn ($char) => ['data' => $char], range('a', 'c')), 'unrelated-stream');

        $commitResult = $this->getEventStore()->commitAll(
            EventsForCommit::createEventsForStreamAndExpectedVersion(
                StreamName::fromString('stream-1'),
                Events::fromArray(array_map(
                    fn (string $char) => $this->convertEvent(['data' => $char]),
                    range('a', 'c')
                )),
                ExpectedVersion::NO_STREAM()
            )->withEventsForStreamAndExpectedVersion(
                StreamName::fromString('stream-2'),
                Events::with(
                    $this->convertEvent(['data' => 'x'])
                ),
                ExpectedVersion::NO_STREAM()
            )->withExpectedVersionForStream(
                StreamName::fromString('unrelated-stream'),
                ExpectedVersion::fromVersion(Version::fromInteger(2))
            ),
        );
        self::assertEventStream($this->getEventStore()->load(VirtualStreamName::all()), [
            ['streamName' => 'unrelated-stream', 'sequenceNumber' => 1, 'version' => 0],
            ['streamName' => 'unrelated-stream', 'sequenceNumber' => 2, 'version' => 1],
            ['streamName' => 'unrelated-stream', 'sequenceNumber' => 3, 'version' => 2],
            ['streamName' => 'stream-1', 'sequenceNumber' => 4, 'version' => 0],
            ['streamName' => 'stream-1', 'sequenceNumber' => 5, 'version' => 1],
            ['streamName' => 'stream-1', 'sequenceNumber' => 6, 'version' => 2],
            ['streamName' => 'stream-2', 'sequenceNumber' => 7, 'version' => 0],
        ]);
        self::assertSame(7, $commitResult->highestCommittedSequenceNumber->value);
        self::assertEquals([
            VersionForStream::create(StreamName::fromString('stream-1'), Version::fromInteger(2)),
            VersionForStream::create(StreamName::fromString('stream-2'), Version::fromInteger(0)),
        ], iterator_to_array($commitResult->versionForStreams));
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
            ['sequenceNumber' => 1, 'type' => 'SomeEventType', 'data' => 'a', 'metadata' => null, 'causationId' => null, 'correlationId' => null, 'streamName' => 'first-stream', 'version' => 0],
            ['sequenceNumber' => 2, 'type' => 'SomeOtherEventType', 'data' => 'b', 'metadata' => null, 'causationId' => null, 'correlationId' => null, 'streamName' => 'first-stream', 'version' => 1],
            ['sequenceNumber' => 3, 'type' => 'SomeEventType', 'data' => 'c', 'metadata' => null, 'causationId' => null, 'correlationId' => null, 'streamName' => 'first-stream', 'version' => 2],
            ['sequenceNumber' => 4, 'type' => 'SomeOtherEventType', 'data' => 'd', 'metadata' => null, 'causationId' => null, 'correlationId' => null, 'streamName' => 'second-stream', 'version' => 0],
            ['sequenceNumber' => 5, 'type' => 'SomeEventType', 'data' => 'e', 'metadata' => null, 'causationId' => null, 'correlationId' => null, 'streamName' => 'second-stream', 'version' => 1],
            ['sequenceNumber' => 6, 'type' => 'SomeOtherEventType', 'data' => 'f', 'metadata' => null, 'causationId' => null, 'correlationId' => null, 'streamName' => 'second-stream', 'version' => 2],
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
            ['sequenceNumber' => 4, 'type' => 'SomeOtherEventType', 'data' => 'd', 'metadata' => null, 'streamName' => 'second-stream', 'version' => 0],
            ['sequenceNumber' => 5, 'type' => 'SomeEventType', 'data' => 'e', 'metadata' => null, 'streamName' => 'second-stream', 'version' => 1],
            ['sequenceNumber' => 6, 'type' => 'SomeOtherEventType', 'data' => 'f', 'metadata' => null, 'streamName' => 'second-stream', 'version' => 2],
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
            ['sequenceNumber' => 2, 'type' => 'SomeOtherEventType', 'data' => 'b', 'metadata' => null, 'streamName' => 'first-stream', 'version' => 1],
            ['sequenceNumber' => 4, 'type' => 'SomeOtherEventType', 'data' => 'd', 'metadata' => null, 'streamName' => 'second-stream', 'version' => 0],
            ['sequenceNumber' => 6, 'type' => 'SomeOtherEventType', 'data' => 'f', 'metadata' => null, 'streamName' => 'second-stream', 'version' => 2],
        ]);
    }

    public function test_deleteStream_does_not_reset_sequenceNumber(): void
    {
        $eventStore = $this->getEventStore();
        if (!$eventStore instanceof WithResetInterface) {
            self::markTestSkipped(sprintf('EventStore %s does not allow reset.', $eventStore::class));
        }

        $this->commitEvents(array_map(static fn ($char) => ['data' => $char], range('a', 'c')), 'first-stream');
        $eventStore->deleteStream(StreamName::fromString('first-stream'));
        $this->commitEvents(array_map(static fn ($char) => ['data' => $char], range('d', 'f')), 'second-stream');

        self::assertEventStream($this->getEventStore()->load(VirtualStreamName::all()), [
            ['sequenceNumber' => 4],
            ['sequenceNumber' => 5],
            ['sequenceNumber' => 6],
        ]);
    }

    public function test_deleteStream_does_reset_version(): void
    {
        $eventStore = $this->getEventStore();
        if (!$eventStore instanceof WithResetInterface) {
            self::markTestSkipped(sprintf('EventStore %s does not allow reset.', $eventStore::class));
        }

        $this->commitEvents(array_map(static fn ($char) => ['data' => $char], range('a', 'c')), 'first-stream', ExpectedVersion::NO_STREAM());
        $eventStore->deleteStream(StreamName::fromString('first-stream'));
        $this->commitEvents(array_map(static fn ($char) => ['data' => $char], range('d', 'f')), 'first-stream', ExpectedVersion::NO_STREAM());

        self::assertEventStream($this->getEventStore()->load(VirtualStreamName::all()), [
            ['streamName' => 'first-stream', 'version' => 0],
            ['streamName' => 'first-stream', 'version' => 1],
            ['streamName' => 'first-stream', 'version' => 2],
        ]);
    }

    public function test_reset_does_reset_sequenceNumber(): void
    {
        $eventStore = $this->getEventStore();
        if (!$eventStore instanceof WithResetInterface) {
            self::markTestSkipped(sprintf('EventStore %s does not allow reset.', $eventStore::class));
        }

        $this->commitEvents(array_map(static fn ($char) => ['data' => $char], range('a', 'c')), 'first-stream');
        $this->commitEvents(array_map(static fn ($char) => ['data' => $char], range('d', 'f')), 'second-stream');
        $eventStore->reset();
        $this->commitEvents(array_map(static fn ($char) => ['data' => $char], range('g', 'i')), 'third-stream');

        self::assertEventStream($this->getEventStore()->load(VirtualStreamName::all()), [
            ['sequenceNumber' => 1],
            ['sequenceNumber' => 2],
            ['sequenceNumber' => 3],
        ]);
    }

    public function test_loaded_events_contain_metadata(): void
    {
        $this->commitEvent(['metadata' => ['foo' => 'bar']]);
        $this->commitEvent(['metadata' => ['bar' => 'baz']]);

        self::assertEventStream($this->getEventStore()->load(VirtualStreamName::all()), [
            ['sequenceNumber' => 1, 'metadata' => ['foo' => 'bar']],
            ['sequenceNumber' => 2, 'metadata' => ['bar' => 'baz']],
        ]);
    }

    public function test_loaded_events_contain_recorded_at(): void
    {
        $defaultSystemTimezone = date_default_timezone_get();

        // Ensure test also passes on non UTC systems
        date_default_timezone_set('America/El_Salvador');

        $firstDate = new \DateTimeImmutable(
            '2024-09-22T12:00:00+00:00'
        );
        self::assertSame($firstDate->getOffset(), 0);
        EventStoreFakeClock::setNow($firstDate);
        $this->commitEvent(['data' => 'a']);

        $secondDate = new \DateTimeImmutable(
            '2024-09-23T13:00:00+00:00'
        );
        EventStoreFakeClock::setNow($secondDate);
        $this->commitEvent(['data' => 'b']);

        self::assertEventStream($this->getEventStore()->load(VirtualStreamName::all()), [
            ['sequenceNumber' => 1, 'recordedAt' => '2024-09-22T12:00:00+00:00'],
            ['sequenceNumber' => 2, 'recordedAt' => '2024-09-23T13:00:00+00:00'],
        ]);

        date_default_timezone_set($defaultSystemTimezone);
    }

    public function test_loaded_events_contain_recorded_at_in_utc(): void
    {
        $firstDateCet = new \DateTimeImmutable(
            '2024-09-22T13:00:00+01:00'
        );
        self::assertSame($firstDateCet->getOffset(), 60 * 60);
        EventStoreFakeClock::setNow($firstDateCet);
        $this->commitEvent(['data' => 'a']);

        $secondDateJst = new \DateTimeImmutable(
            '2024-09-23T22:00:00+09:00'
        );
        self::assertSame($secondDateJst->getOffset(), 9 * 60 * 60);
        EventStoreFakeClock::setNow($secondDateJst);
        $this->commitEvent(['data' => 'b']);

        self::assertEventStream($this->getEventStore()->load(VirtualStreamName::all()), [
            ['sequenceNumber' => 1, 'recordedAt' => '2024-09-22T12:00:00+00:00'],
            ['sequenceNumber' => 2, 'recordedAt' => '2024-09-23T13:00:00+00:00'],
        ]);
    }

    public function test_loaded_events_contain_causation_and_correlation_ids(): void
    {
        $this->commitEvent(['causationId' => 'some-causation-id']);
        $this->commitEvent(['correlationId' => 'some-correlation-id']);

        self::assertEventStream($this->getEventStore()->load(VirtualStreamName::all()), [
            ['sequenceNumber' => 1, 'causationId' => 'some-causation-id', 'correlationId' => null],
            ['sequenceNumber' => 2, 'causationId' => null, 'correlationId' => 'some-correlation-id'],
        ]);
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
    final protected function commitEvents(array $events, string $streamName = 'some-stream', ?ExpectedVersion $expectedVersion = null): CommitResult
    {
        return $this->getEventStore()->commit(StreamName::fromString($streamName), Events::fromArray(array_map($this->convertEvent(...), $events)), $expectedVersion ?? ExpectedVersion::ANY());
    }

    /**
     * @param array{id?: string, type?: string, data?: string, metadata?: array<mixed>, causationId?: string|null, correlationId?: string|null} $event
     * @param string $streamName
     * @param ExpectedVersion|null $expectedVersion
     * @return CommitResult
     */
    final protected function commitEvent(array $event, string $streamName = 'some-stream', ?ExpectedVersion $expectedVersion = null): CommitResult
    {
        return $this->commitEvents([$event], $streamName, $expectedVersion);
    }

    /**
     * @param EventStreamInterface $eventStream
     * @param array<array{id?: string, type?: string, data?: string, metadata?: array<mixed>|null, causationId?: string|null, correlationId?: string|null, streamName?: string, version?: int, sequenceNumber?: int, recordedAt?: string}> $expectedEvents
     */
    final protected static function assertEventStream(EventStreamInterface $eventStream, array $expectedEvents): void
    {
        $actualEvents = [];
        $index = 0;
        foreach ($eventStream as $eventEnvelope) {
            $actualEvents[] = self::eventEnvelopeToArray(isset($expectedEvents[$index]) ? array_keys($expectedEvents[$index]) : ['id', 'type', 'data', 'metadata', 'causationId', 'correlationId', 'streamName', 'version', 'sequenceNumber', 'recordedAt'], $eventEnvelope);
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

    final protected function getEventStore(): EventStoreInterface
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
     * @return array{id?: string, type?: string, data?: string, metadata?: array<mixed>|null, causationId?: string|null, correlationId?: string|null, streamName?: string, version?: int, sequenceNumber?: int, recordedAt?: string}
     */
    private static function eventEnvelopeToArray(array $keys, EventEnvelope $eventEnvelope): array
    {
        $supportedKeys = ['id', 'type', 'data', 'metadata', 'causationId', 'correlationId', 'streamName', 'version', 'sequenceNumber', 'recordedAt'];
        $unsupportedKeys = array_diff($keys, $supportedKeys);
        if ($unsupportedKeys !== []) {
            throw new \InvalidArgumentException(sprintf('Invalid key(s) "%s" for expected event. Allowed keys are: "%s"', implode('", "', $unsupportedKeys), implode('", "', $supportedKeys)), 1651755700);
        }
        $actualAsArray = [
            'id' => $eventEnvelope->event->id->value,
            'type' => $eventEnvelope->event->type->value,
            'data' => $eventEnvelope->event->data->value,
            'metadata' => $eventEnvelope->event->metadata?->value,
            'causationId' => $eventEnvelope->event->causationId?->value,
            'correlationId' => $eventEnvelope->event->correlationId?->value,
            'streamName' => $eventEnvelope->streamName->value,
            'version' => $eventEnvelope->version->value,
            'sequenceNumber' => $eventEnvelope->sequenceNumber->value,
            'recordedAt' => $eventEnvelope->recordedAt->format(\DateTimeImmutable::ATOM),
        ];
        foreach (array_diff($supportedKeys, $keys) as $unusedKey) {
            unset($actualAsArray[$unusedKey]);
        }
        return $actualAsArray;
    }

    /**
     * @param array{id?: string, type?: string, data?: string, metadata?: array<mixed>, causationId?: string|null, correlationId?: string|null} $event
     * @return Event
     */
    final protected function convertEvent(array $event): Event
    {
        return new Event(
            isset($event['id']) ? EventId::fromString($event['id']) : EventId::create(),
            EventType::fromString($event['type'] ?? 'SomeEventType'),
            EventData::fromString($event['data'] ?? ''),
            isset($event['metadata']) ? EventMetadata::fromArray($event['metadata']) : null,
            isset($event['causationId']) ? CausationId::fromString($event['causationId']): null,
            isset($event['correlationId']) ? Event\CorrelationId::fromString($event['correlationId']): null,
        );
    }
}
