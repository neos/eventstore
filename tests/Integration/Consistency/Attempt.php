<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Events;
use Neos\EventStore\Model\EventsForCommit;
use Neos\EventStore\Model\EventStream\ExpectedNoStream;
use Neos\EventStore\Model\EventStream\ExpectedStreamExists;
use Neos\EventStore\Model\EventStream\ExpectedStreamVersion;
use Neos\EventStore\Model\EventStream\ExpectedVersion;

/**
 * One generated commit attempt, before it is executed
 */
final readonly class Attempt
{
    /**
     * @param list<array{stream: string, count: int}> $segments the per-segment layout, in commit order
     * @param list<ExpectedStreamVersion|ExpectedNoStream|ExpectedStreamExists> $constraints the expected stream constraints of this commit
     * @param list<string> $eventIds the ids of all events of this commit, in global commit order
     */
    public function __construct(
        public string $commitId,
        public AttemptShape $shape,
        public Verdict $verdict,
        public string $verdictReason,
        public array $segments,
        public array $constraints,
        public array $eventIds,
        public EventsForCommit $commit,
    ) {
    }

    public function usesLegacyCommitApi(): bool
    {
        return $this->shape->usesLegacyCommitApi();
    }

    /**
     * The stream of a legacy commit() attempt
     *
     * Shapes routed through commit() are generated with exactly one segment and at most one constraint,
     * so the legacy arguments can be derived from the EventsForCommit rather than carried alongside it.
     */
    public function legacyStreamName(): StreamName
    {
        foreach ($this->commit->eventsForStreams as $eventsForStream) {
            return $eventsForStream->streamName;
        }
        throw new \RuntimeException(sprintf('Attempt "%s" has no events', $this->commitId), 1781013010);
    }

    public function legacyEvents(): Events
    {
        foreach ($this->commit->eventsForStreams as $eventsForStream) {
            return $eventsForStream->events;
        }
        throw new \RuntimeException(sprintf('Attempt "%s" has no events', $this->commitId), 1781013011);
    }

    public function legacyExpectedVersion(): ExpectedVersion
    {
        foreach ($this->commit->expectedStreamConstraints as $constraint) {
            return match (true) {
                $constraint instanceof ExpectedNoStream => ExpectedVersion::NO_STREAM(),
                $constraint instanceof ExpectedStreamExists => ExpectedVersion::STREAM_EXISTS(),
                $constraint instanceof ExpectedStreamVersion => ExpectedVersion::fromVersion($constraint->expectedVersion),
            };
        }
        return ExpectedVersion::ANY();
    }

    public function numberOfEvents(): int
    {
        return count($this->eventIds);
    }
}
