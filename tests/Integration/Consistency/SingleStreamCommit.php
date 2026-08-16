<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Events;
use Neos\EventStore\Model\EventsForCommit;
use Neos\EventStore\Model\EventStream\ExpectedNoStream;
use Neos\EventStore\Model\EventStream\ExpectedStreamExists;
use Neos\EventStore\Model\EventStream\ExpectedStreamVersion;
use Neos\EventStore\Model\EventStream\ExpectedVersion;

/**
 * The arguments of an {@see EventStoreInterface::commit()} call
 *
 * Shapes that are executed through commit() are generated with exactly one segment and at most one
 * constraint {@see AttemptShape::commitApi()}, so the arguments can be derived from the
 * {@see EventsForCommit} of the attempt rather than carried alongside it – which also keeps both APIs
 * writing provably identical commits.
 */
final readonly class SingleStreamCommit
{
    private function __construct(
        public StreamName $streamName,
        public Events $events,
        public ExpectedVersion $expectedVersion,
    ) {
    }

    public static function fromEventsForCommit(EventsForCommit $commit): self
    {
        foreach ($commit->eventsForStreams as $eventsForStream) {
            return new self($eventsForStream->streamName, $eventsForStream->events, self::expectedVersion($commit));
        }
        throw new \RuntimeException('A commit has to contain events for at least one stream', 1781013010);
    }

    private static function expectedVersion(EventsForCommit $commit): ExpectedVersion
    {
        foreach ($commit->expectedStreamConstraints as $constraint) {
            return match (true) {
                $constraint instanceof ExpectedNoStream => ExpectedVersion::NO_STREAM(),
                $constraint instanceof ExpectedStreamExists => ExpectedVersion::STREAM_EXISTS(),
                $constraint instanceof ExpectedStreamVersion => ExpectedVersion::fromVersion($constraint->expectedVersion),
            };
        }
        return ExpectedVersion::ANY();
    }
}
