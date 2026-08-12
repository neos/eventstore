<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\Model\Event;
use Neos\EventStore\Model\Event\EventData;
use Neos\EventStore\Model\Event\EventId;
use Neos\EventStore\Model\Event\EventType;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Event\Version;
use Neos\EventStore\Model\Events;
use Neos\EventStore\Model\EventsForCommit;
use Neos\EventStore\Model\EventsForStream;
use Neos\EventStore\Model\EventsForStreams;
use Neos\EventStore\Model\EventStream\ExpectedNoStream;
use Neos\EventStore\Model\EventStream\ExpectedStreamConstraints;
use Neos\EventStore\Model\EventStream\ExpectedStreamExists;
use Neos\EventStore\Model\EventStream\ExpectedStreamVersion;
use Neos\EventStore\Model\EventStream\MaybeVersion;

/**
 * Builds randomized commit attempts from the {@see AttemptShape} catalogue
 *
 * Every attempt is tagged with a {@see Verdict} that says what can be *proven* about its outcome from
 * the process' monotone {@see StreamKnowledge} alone. That is what turns an otherwise timing-dependent
 * stress test into one with a real oracle: a MUST_FAIL attempt that succeeds, or a MUST_SUCCEED attempt
 * that is rejected, is a bug regardless of how the processes interleaved.
 */
final class AttemptGenerator
{
    /** @var list<StreamName> */
    private readonly array $streamPool;

    /** @var list<EventType> */
    private readonly array $eventTypes;

    private int $counter = 0;

    /**
     * @param \Closure(StreamName): MaybeVersion $versionReader reads the current version of a stream from the store
     */
    public function __construct(
        private readonly RunManifest $manifest,
        private readonly StreamKnowledge $knowledge,
        private readonly \Closure $versionReader,
        private readonly string $commitIdPrefix,
    ) {
        $this->streamPool = $manifest->streamNames();
        $this->eventTypes = $manifest->eventTypes();
    }

    public function generate(): Attempt
    {
        $shape = $this->pickShape();
        $plan = $this->plan($shape);
        [$verdict, $verdictReason] = $this->judge($plan);
        $commitId = $this->commitIdPrefix . '-' . (++$this->counter);

        $total = $plan->totalNumberOfEvents();
        $events = [];
        $eventIds = [];
        for ($position = 1; $position <= $total; $position++) {
            $eventId = EventId::create();
            $eventIds[] = $eventId->value;
            $payload = ['r' => $this->manifest->runId, 'c' => $commitId, 'i' => $position, 'n' => $total];
            $events[] = new Event(
                $eventId,
                $this->either($this->eventTypes),
                EventData::fromString(json_encode($payload, JSON_THROW_ON_ERROR)),
            );
        }

        $eventsForStreamList = [];
        $segments = [];
        $offset = 0;
        foreach ($plan->segments as $segment) {
            $eventsForStreamList[] = EventsForStream::create(
                $segment['streamName'],
                Events::fromArray(array_slice($events, $offset, $segment['count'])),
            );
            $segments[] = ['stream' => $segment['streamName']->value, 'count' => $segment['count']];
            $offset += $segment['count'];
        }
        // the plan guarantees at least one segment, so this is never empty
        $firstEventsForStream = array_shift($eventsForStreamList);

        return new Attempt(
            commitId: $commitId,
            shape: $shape,
            verdict: $verdict,
            verdictReason: $verdictReason,
            segments: $segments,
            constraints: $plan->constraints,
            eventIds: $eventIds,
            commit: EventsForCommit::create(
                EventsForStreams::create($firstEventsForStream, ...$eventsForStreamList),
                ExpectedStreamConstraints::create(...$plan->constraints),
            ),
        );
    }

    // --- Shape selection -----

    private function pickShape(): AttemptShape
    {
        $available = [];
        foreach (AttemptShape::all() as $shape) {
            if ($shape->requiredStreamCount() > count($this->streamPool)) {
                continue;
            }
            if ($shape->requiresKnownNonEmptyStream() && $this->knownNonEmptyStreams() === []) {
                continue;
            }
            if ($shape->requiresStaleableStream() && $this->staleableStreams() === []) {
                continue;
            }
            $available[] = $shape;
        }
        // COMMIT_ANY has no prerequisites, so this is never empty
        return $this->either($available);
    }

    private function plan(AttemptShape $shape): AttemptPlan
    {
        return match ($shape) {
            AttemptShape::COMMIT_ANY, AttemptShape::SINGLE_UNCONSTRAINED
                => new AttemptPlan([$this->segment($this->either($this->streamPool))], []),

            AttemptShape::COMMIT_NO_STREAM
                => $this->singleStreamPlan(static fn (StreamName $streamName) => ExpectedNoStream::create($streamName)),

            AttemptShape::COMMIT_VERSION, AttemptShape::SINGLE_VERSION
                => $this->singleStreamPlan($this->freshConstraint(...)),

            AttemptShape::COMMIT_STALE_VERSION, AttemptShape::SINGLE_STALE_VERSION
                => $this->staleablePlan(),

            AttemptShape::SINGLE_STREAM_EXISTS
                => $this->knownStreamPlan(static fn (StreamName $streamName) => ExpectedStreamExists::create($streamName)),

            AttemptShape::SINGLE_NO_STREAM_ON_EXISTING
                => $this->knownStreamPlan(static fn (StreamName $streamName) => ExpectedNoStream::create($streamName)),

            AttemptShape::MULTI_ALL_CONSTRAINED => $this->multiStreamPlan(true),
            AttemptShape::MULTI_PARTIAL => $this->multiStreamPlan(false),
            AttemptShape::SAME_STREAM_SEGMENTED => $this->segmentedPlan(),
            AttemptShape::FOREIGN_MONOTONE => $this->foreignMonotonePlan(),
            AttemptShape::FOREIGN_VERSION => $this->foreignVersionPlan(),
        };
    }

    /**
     * @param \Closure(StreamName): (ExpectedStreamVersion|ExpectedNoStream|ExpectedStreamExists) $constraintFactory
     */
    private function singleStreamPlan(\Closure $constraintFactory): AttemptPlan
    {
        $streamName = $this->either($this->streamPool);
        return new AttemptPlan([$this->segment($streamName)], [$constraintFactory($streamName)]);
    }

    /**
     * @param \Closure(StreamName): (ExpectedStreamVersion|ExpectedNoStream|ExpectedStreamExists) $constraintFactory
     */
    private function knownStreamPlan(\Closure $constraintFactory): AttemptPlan
    {
        $streamName = $this->either($this->knownNonEmptyStreams());
        return new AttemptPlan([$this->segment($streamName)], [$constraintFactory($streamName)]);
    }

    private function staleablePlan(): AttemptPlan
    {
        $streamName = $this->either($this->staleableStreams());
        return new AttemptPlan([$this->segment($streamName)], [$this->staleConstraint($streamName)]);
    }

    private function multiStreamPlan(bool $constrainAll): AttemptPlan
    {
        // the shape is only offered when the pool holds at least two streams
        $streamNames = $this->distinctStreams(random_int(2, max(2, min(3, count($this->streamPool)))));
        $segments = [$this->segment($streamNames[0])];
        foreach (array_slice($streamNames, 1) as $streamName) {
            $segments[] = $this->segment($streamName);
        }
        $constrained = $constrainAll ? $streamNames : array_slice($streamNames, 0, random_int(1, max(1, count($streamNames) - 1)));
        $constraints = [];
        foreach ($constrained as $streamName) {
            $constraints[] = $this->freshConstraint($streamName);
        }
        return new AttemptPlan($segments, $constraints);
    }

    /**
     * The same stream written more than once within a single commit, optionally interleaved with another
     * stream – versions have to continue across the segments rather than restart per segment
     */
    private function segmentedPlan(): AttemptPlan
    {
        $streamName = $this->either($this->streamPool);
        $interleave = count($this->streamPool) >= 2 && random_int(0, 1) === 1;
        if (!$interleave) {
            $segments = [];
            for ($i = 0, $numberOfSegments = random_int(2, 3); $i < $numberOfSegments; $i++) {
                $segments[] = $this->segment($streamName);
            }
            return new AttemptPlan($segments, [$this->freshConstraint($streamName)]);
        }
        $otherStreamName = $this->either($this->streamPoolWithout($streamName));
        $segments = [$this->segment($streamName), $this->segment($otherStreamName), $this->segment($streamName)];
        return new AttemptPlan($segments, [$this->freshConstraint($streamName), $this->freshConstraint($otherStreamName)]);
    }

    /**
     * Constrains a stream that is not written to by this commit, with a *monotone* constraint
     *
     * "exist" and a stale version are the two constraints whose outcome is decided the moment they are
     * built: streams only ever grow, so no concurrent write can turn either of them around. That makes
     * this the verdict-carrying half of the foreign coverage – and, for exactly the same reason, the half
     * that is blind to the race, because there is nothing for a concurrent writer to invalidate.
     * {@see foreignVersionPlan()} is the half that actually races.
     */
    private function foreignMonotonePlan(): AttemptPlan
    {
        $constrainedStreamName = $this->either($this->knownNonEmptyStreams());
        $writtenStreamName = $this->either($this->streamPoolWithout($constrainedStreamName));
        $constraint = $this->knowledge->isStaleable($constrainedStreamName) && random_int(0, 1) === 1
            ? $this->staleConstraint($constrainedStreamName)
            : ExpectedStreamExists::create($constrainedStreamName);
        return new AttemptPlan([$this->segment($writtenStreamName)], [$constraint]);
    }

    /**
     * Constrains a stream that is not written to by this commit, on a version read moments before
     *
     * The one shape whose constraint is both foreign *and* invalidatable: a concurrent commit to the
     * constrained stream between the read and the write has to make this commit fail. No verdict can be
     * attached to it – the process cannot know whether anyone else got there first – so it is
     * {@see ConsistencyValidator::checkConstraintsHeldWhenTheCommitLanded()} that judges it afterwards,
     * from the global order of the store.
     *
     * A store that validates foreign constraints by reading them without holding a lock passes every
     * other shape and fails this one: an exact-version constraint on a stream the commit *writes* is
     * still guarded by the version conflict on the write itself, but here there is no write to conflict.
     */
    private function foreignVersionPlan(): AttemptPlan
    {
        $constrainedStreamName = $this->either($this->streamPool);
        $writtenStreamName = $this->either($this->streamPoolWithout($constrainedStreamName));
        return new AttemptPlan([$this->segment($writtenStreamName)], [$this->freshConstraint($constrainedStreamName)]);
    }

    // --- Constraint construction -----

    /**
     * A constraint built from a fresh read – its outcome depends on the interleaving
     */
    private function freshConstraint(StreamName $streamName): ExpectedStreamVersion|ExpectedNoStream
    {
        $maybeVersion = ($this->versionReader)($streamName);
        $this->knowledge->recordObservation($streamName, $maybeVersion);
        if ($maybeVersion->isNothing()) {
            return ExpectedNoStream::create($streamName);
        }
        return ExpectedStreamVersion::create($streamName, $maybeVersion->unwrap());
    }

    /**
     * A version strictly below a known lower bound – can never be satisfied again
     */
    private function staleConstraint(StreamName $streamName): ExpectedStreamVersion
    {
        $lowerBound = $this->knowledge->lowerBound($streamName);
        if ($lowerBound === null || $lowerBound < 1) {
            throw new \RuntimeException(sprintf('Cannot build a stale constraint for stream "%s"', $streamName->value), 1781013021);
        }
        return ExpectedStreamVersion::create($streamName, Version::fromInteger(random_int(0, $lowerBound - 1)));
    }

    // --- Verdict -----

    /**
     * @return array{0: Verdict, 1: string}
     */
    private function judge(AttemptPlan $plan): array
    {
        $reasons = [];
        $allSatisfied = true;
        foreach ($plan->constraints as $constraint) {
            [$verdict, $reason] = $this->judgeConstraint($constraint);
            if ($verdict === Verdict::MUST_FAIL) {
                return [Verdict::MUST_FAIL, $reason];
            }
            if ($verdict !== Verdict::MUST_SUCCEED) {
                $allSatisfied = false;
            }
            $reasons[] = $reason;
        }
        if ($allSatisfied) {
            return [Verdict::MUST_SUCCEED, $reasons === [] ? 'no constraints' : implode('; ', $reasons)];
        }
        return [Verdict::UNDECIDABLE, implode('; ', $reasons)];
    }

    /**
     * @return array{0: Verdict, 1: string}
     */
    private function judgeConstraint(ExpectedStreamVersion|ExpectedNoStream|ExpectedStreamExists $constraint): array
    {
        $streamName = $constraint->streamName;
        $lowerBound = $this->knowledge->lowerBound($streamName);
        if ($constraint instanceof ExpectedStreamVersion) {
            if ($lowerBound !== null && $constraint->expectedVersion->value < $lowerBound) {
                return [Verdict::MUST_FAIL, sprintf('%s but "%s" is known to be at version %d or higher', $constraint->toDebugString(), $streamName->value, $lowerBound)];
            }
            return [Verdict::UNDECIDABLE, sprintf('%s racing', $constraint->toDebugString())];
        }
        if ($constraint instanceof ExpectedNoStream) {
            if ($lowerBound !== null) {
                return [Verdict::MUST_FAIL, sprintf('%s but "%s" is known to be non-empty (version %d)', $constraint->toDebugString(), $streamName->value, $lowerBound)];
            }
            return [Verdict::UNDECIDABLE, sprintf('%s racing', $constraint->toDebugString())];
        }
        if ($lowerBound !== null) {
            return [Verdict::MUST_SUCCEED, sprintf('%s and "%s" is known to be non-empty (version %d)', $constraint->toDebugString(), $streamName->value, $lowerBound)];
        }
        return [Verdict::UNDECIDABLE, sprintf('%s racing', $constraint->toDebugString())];
    }

    // --- Helpers -----

    /**
     * @return array{streamName: StreamName, count: int}
     */
    private function segment(StreamName $streamName): array
    {
        return ['streamName' => $streamName, 'count' => random_int(1, $this->manifest->maxEventsPerSegment)];
    }

    /**
     * @return list<StreamName>
     */
    private function knownNonEmptyStreams(): array
    {
        return array_values(array_filter($this->streamPool, fn (StreamName $streamName) => $this->knowledge->isKnownNonEmpty($streamName)));
    }

    /**
     * The pool without one particular stream, for shapes that need a *second*, distinct stream
     *
     * @return list<StreamName>
     */
    private function streamPoolWithout(StreamName $streamName): array
    {
        return array_values(array_filter($this->streamPool, static fn (StreamName $candidate) => !$candidate->equals($streamName)));
    }

    /**
     * @return list<StreamName>
     */
    private function staleableStreams(): array
    {
        return array_values(array_filter($this->streamPool, fn (StreamName $streamName) => $this->knowledge->isStaleable($streamName)));
    }

    /**
     * @return non-empty-list<StreamName>
     */
    private function distinctStreams(int $number): array
    {
        $pool = $this->streamPool;
        shuffle($pool);
        $streamNames = array_slice($pool, 0, max(1, $number));
        if ($streamNames === []) {
            throw new \RuntimeException('The stream pool is empty', 1781013023);
        }
        return $streamNames;
    }

    /**
     * @template T
     * @param list<T> $choices
     * @return T
     */
    private function either(array $choices): mixed
    {
        if ($choices === []) {
            throw new \RuntimeException('Cannot choose from an empty list', 1781013022);
        }
        return $choices[random_int(0, count($choices) - 1)];
    }
}
