<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\Model\EventsForCommit;
use Neos\EventStore\Model\EventStream\ExpectedNoStream;
use Neos\EventStore\Model\EventStream\ExpectedStreamExists;
use Neos\EventStore\Model\EventStream\ExpectedStreamVersion;

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

    public function commitApi(): CommitApi
    {
        return $this->shape->commitApi();
    }

    public function singleStreamCommit(): SingleStreamCommit
    {
        return SingleStreamCommit::fromEventsForCommit($this->commit);
    }

    public function numberOfEvents(): int
    {
        return count($this->eventIds);
    }
}
