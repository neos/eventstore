<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\Model\EventStream\ExpectedStreamConstraints;

/**
 * The skeleton of an attempt: which streams get how many events in which order, and what is constrained
 *
 * Separated from {@see Attempt} because the shape is decided (and the constraint verdict derived) before
 * any events exist – the number of events of the whole commit has to be known in order to stamp each
 * event with its position within the commit.
 */
final readonly class AttemptPlan
{
    private function __construct(
        public Segments $segments,
        public ExpectedStreamConstraints $constraints,
    ) {
    }

    public static function create(Segments $segments, ExpectedStreamConstraints $constraints): self
    {
        return new self($segments, $constraints);
    }

    public static function unconstrained(Segments $segments): self
    {
        return new self($segments, ExpectedStreamConstraints::none());
    }

    public function totalNumberOfEvents(): int
    {
        return $this->segments->totalNumberOfEvents();
    }
}
