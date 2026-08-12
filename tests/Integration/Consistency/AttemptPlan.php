<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\EventStream\ExpectedNoStream;
use Neos\EventStore\Model\EventStream\ExpectedStreamExists;
use Neos\EventStore\Model\EventStream\ExpectedStreamVersion;

/**
 * The skeleton of an attempt: which streams get how many events in which order, and what is constrained
 *
 * Separated from {@see Attempt} because the shape is decided (and the constraint verdict derived) before
 * any events exist – the number of events of the whole commit has to be known in order to stamp each
 * event with its position within the commit.
 */
final readonly class AttemptPlan
{
    /**
     * @param non-empty-list<array{streamName: StreamName, count: int}> $segments in commit order; the same stream may appear more than once
     * @param list<ExpectedStreamVersion|ExpectedNoStream|ExpectedStreamExists> $constraints at most one per stream
     */
    public function __construct(
        public array $segments,
        public array $constraints,
    ) {
    }

    public function totalNumberOfEvents(): int
    {
        $total = 0;
        foreach ($this->segments as $segment) {
            $total += $segment['count'];
        }
        return $total;
    }
}
