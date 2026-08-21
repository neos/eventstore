<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\Model\EventsForCommit;
use Neos\EventStore\Model\EventStream\ExpectedStreamConstraints;

/**
 * One generated commit attempt, before it is executed
 *
 * Everything the attempt is made of lives in its {@see EventsForCommit} – layout, constraints and events
 * are derived from it rather than carried next to it, so what gets logged can never drift from what gets
 * committed. What cannot be derived is what makes the attempt an oracle: the {@see Judgement} the
 * generator was able to make about its outcome beforehand.
 */
final readonly class Attempt
{
    private function __construct(
        public string $commitId,
        public AttemptShape $shape,
        public Judgement $judgement,
        public EventsForCommit $commit,
    ) {
    }

    public static function create(string $commitId, AttemptShape $shape, Judgement $judgement, EventsForCommit $commit): self
    {
        return new self($commitId, $shape, $judgement, $commit);
    }

    public function commitApi(): CommitApi
    {
        return $this->shape->commitApi();
    }

    public function segments(): Segments
    {
        return Segments::fromEventsForStreams($this->commit->eventsForStreams);
    }

    public function constraints(): ExpectedStreamConstraints
    {
        return $this->commit->expectedStreamConstraints;
    }

    public function eventIds(): EventIds
    {
        return EventIds::fromEventsForStreams($this->commit->eventsForStreams);
    }

    public function singleStreamCommit(): SingleStreamCommit
    {
        return SingleStreamCommit::fromEventsForCommit($this->commit);
    }
}
