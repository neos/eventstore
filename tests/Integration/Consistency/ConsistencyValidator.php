<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\Event\SequenceNumber;

/**
 * Cross-checks the contents of the event store against the op logs of every worker process
 *
 * Both directions are checked: every logged success has to be fully and correctly present, every logged
 * failure has to have written nothing at all, and every event in the store has to belong to a logged
 * success of this very run.
 *
 * The op logs of all processes together also reconstruct what no single process could know: read in the
 * global order of the store, they say whether the constraints of a commit still held when it landed
 * {@see checkConstraintsHeldWhenTheCommitLanded()}.
 *
 * A run is validated in two phases: the {@see Attempts} and the {@see StoreContents} are read first, and
 * every check then works on those two alone – no check reads anything itself, and none of them depend on
 * the order they are run in.
 */
final readonly class ConsistencyValidator
{
    private function __construct(
        private RunManifest $manifest,
        private ValidationReport $report,
        private Attempts $attempts,
        private StoreContents $store,
    ) {
    }

    public static function validate(EventStoreInterface $eventStore, RunManifest $manifest): ValidationReport
    {
        $report = new ValidationReport($manifest);
        $attempts = Attempts::fromOpLogs($manifest, $report);
        $validator = new self($manifest, $report, $attempts, StoreContents::read($eventStore, $manifest, $attempts, $report));
        $validator->checkSuccessesArePresent();
        $validator->checkFailuresWroteNothing();
        $validator->checkConstraintsHeldWhenTheCommitLanded();
        $validator->checkStreamVersionsAreContiguous();
        $validator->checkVerdicts();
        $validator->checkCoverageAndLiveness();
        return $report;
    }

    // --- Checks -----

    /**
     * Every logged success has to be present in full, in the layout it declared, ending at the versions
     * the store itself reported back
     */
    private function checkSuccessesArePresent(): void
    {
        foreach ($this->attempts as $attempt) {
            if ($attempt->result->outcome !== Outcome::SUCCESS) {
                continue;
            }
            $found = $this->store->eventsOfCommit($attempt->commitId);
            if ($found->isEmpty()) {
                $this->report->addViolation(Violation::PHANTOM_SUCCESS, sprintf('%s – but none of its %d events are in the store', $attempt->toDebugString(), count($attempt->eventIds)));
                continue;
            }
            if (count($found) !== count($attempt->eventIds)) {
                $this->report->addViolation(Violation::PARTIAL_WRITE, sprintf('%s – %d of %d events are in the store', $attempt->toDebugString(), count($found), count($attempt->eventIds)));
                continue;
            }
            $eventsInCommitOrder = $found->sortedByPositionInCommit();
            $this->checkCommitLayout($attempt, $eventsInCommitOrder);
            $this->checkCommitVersions($attempt, $eventsInCommitOrder);
        }
    }

    /**
     * The events of one commit have to be the declared ones, in the declared streams, in commit order
     */
    private function checkCommitLayout(OpLogEntry $attempt, StoredEvents $eventsInCommitOrder): void
    {
        $expectedStreamNames = $attempt->segments->streamNamePerEvent();
        $previousSequenceNumber = SequenceNumber::none();
        $index = 0;
        foreach ($eventsInCommitOrder as $event) {
            $expectedEventId = $attempt->eventIds->at($index);
            if ($expectedEventId === null || $expectedEventId->value !== $event->id->value) {
                $this->report->addViolation(Violation::EVENT_ID_MISMATCH, sprintf('%s – event %d of the commit is "%s" but "%s" was written', $attempt->toDebugString(), $index + 1, $expectedEventId?->value ?? '(none)', $event->id->value));
            }
            $expectedStreamName = $expectedStreamNames[$index] ?? null;
            if ($expectedStreamName === null || !$expectedStreamName->equals($event->streamName)) {
                $this->report->addViolation(Violation::SEGMENT_MISMATCH, sprintf('%s – event %d belongs in stream "%s" but was written to "%s"', $attempt->toDebugString(), $index + 1, $expectedStreamName?->value ?? '(none)', $event->streamName->value));
            }
            if ($event->sequenceNumber->value <= $previousSequenceNumber->value) {
                $this->report->addViolation(Violation::COMMIT_ORDER_MISMATCH, sprintf('%s – event %d has sequence number %d which does not follow the previous event of the same commit (%d)', $attempt->toDebugString(), $index + 1, $event->sequenceNumber->value, $previousSequenceNumber->value));
            }
            $previousSequenceNumber = $event->sequenceNumber;
            $index++;
        }
    }

    /**
     * Per stream of one commit: the versions have to continue across the segments of that stream rather
     * than restart per segment, and they have to end at the version the store reported back
     */
    private function checkCommitVersions(OpLogEntry $attempt, StoredEvents $eventsInCommitOrder): void
    {
        foreach ($eventsInCommitOrder->groupedByStreamName() as $eventsOfStream) {
            if (!$eventsOfStream->versionsAreConsecutive()) {
                $this->report->addViolation(Violation::COMMIT_VERSION_GAP, sprintf(
                    '%s – versions written to stream "%s" are not consecutive: %s',
                    $attempt->toDebugString(),
                    $eventsOfStream->streamName()->value,
                    $eventsOfStream->versionsToDebugString(),
                ));
            }
            $reportedVersion = $attempt->result->committedVersions->versionFor($eventsOfStream->streamName());
            $actualVersion = $eventsOfStream->last()->version;
            if ($reportedVersion !== null && $reportedVersion->value !== $actualVersion->value) {
                $this->report->addViolation(Violation::RESULT_VERSION_MISMATCH, sprintf(
                    '%s – reported version %d for stream "%s" but the last event of that stream is at version %d',
                    $attempt->toDebugString(),
                    $reportedVersion->value,
                    $eventsOfStream->streamName()->value,
                    $actualVersion->value,
                ));
            }
        }
    }

    /**
     * A rejected commit is only correct if it is also atomic: nothing at all may have been written
     */
    private function checkFailuresWroteNothing(): void
    {
        foreach ($this->attempts as $attempt) {
            if ($attempt->result->outcome === Outcome::SUCCESS) {
                continue;
            }
            $found = $this->store->eventsOfCommit($attempt->commitId);
            if ($found->isEmpty()) {
                continue;
            }
            $this->report->addViolation(Violation::NON_ATOMIC_ROLLBACK, sprintf(
                '%s – but %d of its %d events are in the store (e.g. "%s" in stream "%s" at version %d)',
                $attempt->toDebugString(),
                count($found),
                count($attempt->eventIds),
                $found->first()->id->value,
                $found->first()->streamName->value,
                $found->first()->version->value,
            ));
        }
    }

    /**
     * Every constraint of a successful commit has to have held at the moment that commit landed
     *
     * This is the only check that relates commits of *different* processes to one another, and therefore
     * the only one that can see a violated constraint on a stream the commit does not write to. Such a
     * commit is indistinguishable from a legal one when looked at on its own – its events are complete,
     * atomic and correctly versioned, they are simply in another stream – and the process that issued it
     * cannot prove anything about it either, because whether someone else got there first is exactly what
     * it does not know. Only the finished store, read in its global order, tells.
     *
     * The sequence number is the order the store publishes, so that is the order constraints are judged
     * in: the commit is placed at the sequence number of its *first* event and every constraint is
     * re-evaluated against the version its stream had strictly before that point. Whatever was written
     * between the first and the last event of the commit is deliberately ignored, so a commit is only
     * ever accused of a constraint that was already stale before it started writing.
     */
    private function checkConstraintsHeldWhenTheCommitLanded(): void
    {
        foreach ($this->attempts as $attempt) {
            if ($attempt->result->outcome !== Outcome::SUCCESS) {
                continue;
            }
            $found = $this->store->eventsOfCommit($attempt->commitId);
            if ($found->isEmpty()) {
                // a success that wrote nothing has no place in the order – already reported as PHANTOM_SUCCESS
                continue;
            }
            $sequenceNumber = $found->first()->sequenceNumber;
            foreach ($attempt->constraints as $constraint) {
                $maybeVersion = $this->store->eventsOfStream($constraint->streamName)->versionBefore($sequenceNumber);
                if ($constraint->isSatisfiedBy($maybeVersion)) {
                    continue;
                }
                $this->report->addViolation(Violation::STALE_CONSTRAINT_ACCEPTED, sprintf(
                    '%s – but stream "%s" was at %s by the time the commit landed (sequence number %d)',
                    $attempt->toDebugString(),
                    $constraint->streamName->value,
                    $maybeVersion->isNothing() ? 'no version at all' : 'version ' . $maybeVersion->unwrap()->value,
                    $sequenceNumber->value,
                ));
            }
        }
    }

    /**
     * Independent of any commit: a stream has to be versions 0..n-1, ascending with the sequence number
     */
    private function checkStreamVersionsAreContiguous(): void
    {
        foreach ($this->store->perStream() as $eventsOfStream) {
            $index = 0;
            foreach ($eventsOfStream as $event) {
                if ($event->version->value !== $index) {
                    $this->report->addViolation(Violation::VERSION_GAP, sprintf(
                        'Event "%s" is number %d in stream "%s" (sequence number %d) so it should have version %d but has %d',
                        $event->id->value,
                        $index + 1,
                        $event->streamName->value,
                        $event->sequenceNumber->value,
                        $index,
                        $event->version->value,
                    ));
                    break;
                }
                $index++;
            }
        }
    }

    /**
     * The verdict checks: what the generator was able to prove before the attempt ran
     */
    private function checkVerdicts(): void
    {
        foreach ($this->attempts as $attempt) {
            if ($attempt->result->outcome === Outcome::UNEXPECTED_ERROR) {
                $this->report->addViolation(Violation::UNEXPECTED_ERROR, $attempt->toDebugString());
                continue;
            }
            if ($attempt->judgement->verdict === Verdict::MUST_FAIL && $attempt->result->outcome === Outcome::SUCCESS) {
                $this->report->addViolation(Violation::MUST_FAIL_SUCCEEDED, sprintf('%s – %s', $attempt->toDebugString(), $attempt->judgement->reason));
                continue;
            }
            if (!$this->manifest->assertMustSucceedIsNotRejected) {
                continue;
            }
            if ($attempt->judgement->verdict === Verdict::MUST_SUCCEED && $attempt->result->outcome === Outcome::REJECTED) {
                $this->report->addViolation(Violation::MUST_SUCCEED_REJECTED, sprintf('%s – %s', $attempt->toDebugString(), $attempt->judgement->reason));
            }
        }
    }

    /**
     * Guards against a degenerate run: a store that rejects (or swallows) everything must not pass, and a
     * shape that never actually got exercised must not be mistaken for a shape that passed
     */
    private function checkCoverageAndLiveness(): void
    {
        foreach (AttemptShape::all() as $shape) {
            $total = $this->report->countsForShape($shape)->total();
            if ($total < $this->manifest->minAttemptsPerShape) {
                $this->report->addViolation(Violation::UNDER_COVERED_SHAPE, sprintf('Shape "%s" was attempted %d times, expected at least %d', $shape->value, $total, $this->manifest->minAttemptsPerShape));
            }
        }
        $satisfiable = $this->report->totalSatisfiable();
        if ($satisfiable === 0) {
            return;
        }
        $successRate = $this->report->totalSuccesses() / $satisfiable;
        if ($successRate < $this->manifest->minSuccessRate) {
            $this->report->addViolation(Violation::LOW_SUCCESS_RATE, sprintf('Only %.1f%% of the %d attempts that were not doomed by construction succeeded, expected at least %.1f%%', 100 * $successRate, $satisfiable, 100 * $this->manifest->minSuccessRate));
        }
        $emptyStreams = [];
        foreach ($this->manifest->streamNames() as $streamName) {
            if ($this->store->eventsOfStream($streamName)->isEmpty()) {
                $emptyStreams[] = $streamName->value;
            }
        }
        if ($emptyStreams !== []) {
            $this->report->addNote(sprintf('%d of %d streams received no events at all', count($emptyStreams), $this->manifest->numberOfStreams));
        }
    }
}
