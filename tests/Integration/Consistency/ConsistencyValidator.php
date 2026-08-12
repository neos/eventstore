<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\Event\Version;
use Neos\EventStore\Model\EventStream\MaybeVersion;
use Neos\EventStore\Model\EventStream\VirtualStreamName;

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
 */
final class ConsistencyValidator
{
    /**
     * Events found in the store, grouped by the commit that claims them
     *
     * @var array<string, list<array{eventId: string, stream: string, version: int, sequenceNumber: int, position: int, total: int}>>
     */
    private array $eventsByCommitId = [];

    /**
     * Events found in the store, grouped by stream, in global sequence order
     *
     * @var array<string, list<array{version: int, sequenceNumber: int, eventId: string}>>
     */
    private array $eventsByStream = [];

    /** @var array<string, OpLogEntry> */
    private array $attemptsByCommitId = [];

    private function __construct(
        private readonly RunManifest $manifest,
        private readonly ValidationReport $report,
    ) {
    }

    public static function validate(EventStoreInterface $eventStore, RunManifest $manifest): ValidationReport
    {
        $validator = new self($manifest, new ValidationReport($manifest));
        $validator->readOpLogs();
        $validator->readStore($eventStore);
        $validator->checkSuccessesArePresent();
        $validator->checkFailuresWroteNothing();
        $validator->checkConstraintsHeldWhenTheCommitLanded();
        $validator->checkStreamVersionsAreContiguous();
        $validator->checkVerdicts();
        $validator->checkCoverageAndLiveness();
        return $validator->report;
    }

    // --- Reading -----

    private function readOpLogs(): void
    {
        foreach (OpLog::readAll($this->manifest) as $entry) {
            if (isset($this->attemptsByCommitId[$entry->commitId])) {
                $this->report->addViolation('DUPLICATE_COMMIT_ID', sprintf('commitId "%s" was logged more than once', $entry->commitId));
                continue;
            }
            $this->attemptsByCommitId[$entry->commitId] = $entry;
            $this->report->recordAttempt($entry);
        }
        if ($this->attemptsByCommitId === []) {
            $this->report->addViolation('NO_ATTEMPTS', sprintf('No op log entries found in "%s" – did the write phase run?', $this->manifest->directory));
        }
    }

    private function readStore(EventStoreInterface $eventStore): void
    {
        $lastSequenceNumber = 0;
        $numberOfEvents = 0;
        foreach ($eventStore->load(VirtualStreamName::all()) as $eventEnvelope) {
            $numberOfEvents++;
            $sequenceNumber = $eventEnvelope->sequenceNumber->value;
            if ($sequenceNumber <= $lastSequenceNumber) {
                $this->report->addViolation('SEQUENCE_NOT_ASCENDING', sprintf(
                    'Event "%s" has sequence number %d which does not exceed the previous one (%d)',
                    $eventEnvelope->event->id->value,
                    $sequenceNumber,
                    $lastSequenceNumber,
                ));
            }
            $lastSequenceNumber = $sequenceNumber;

            $payload = self::decodePayload($eventEnvelope->event->data->value);
            if ($payload === null) {
                $this->report->addViolation('ORPHAN_EVENT', sprintf(
                    'Event "%s" in stream "%s" (sequence number %d) has a payload that was not written by this harness: %s',
                    $eventEnvelope->event->id->value,
                    $eventEnvelope->streamName->value,
                    $sequenceNumber,
                    substr($eventEnvelope->event->data->value, 0, 80),
                ));
                continue;
            }
            $this->eventsByStream[$eventEnvelope->streamName->value][] = [
                'version' => $eventEnvelope->version->value,
                'sequenceNumber' => $sequenceNumber,
                'eventId' => $eventEnvelope->event->id->value,
            ];
            if ($payload['r'] !== $this->manifest->runId) {
                $this->report->addViolation('ORPHAN_EVENT', sprintf(
                    'Event "%s" in stream "%s" belongs to run "%s" instead of "%s" – the store was not reset',
                    $eventEnvelope->event->id->value,
                    $eventEnvelope->streamName->value,
                    $payload['r'],
                    $this->manifest->runId,
                ));
                continue;
            }
            $attempt = $this->attemptsByCommitId[$payload['c']] ?? null;
            if ($attempt === null) {
                $this->report->addViolation('ORPHAN_EVENT', sprintf(
                    'Event "%s" in stream "%s" claims commit "%s" which was never logged',
                    $eventEnvelope->event->id->value,
                    $eventEnvelope->streamName->value,
                    $payload['c'],
                ));
                continue;
            }
            $this->eventsByCommitId[$payload['c']][] = [
                'eventId' => $eventEnvelope->event->id->value,
                'stream' => $eventEnvelope->streamName->value,
                'version' => $eventEnvelope->version->value,
                'sequenceNumber' => $sequenceNumber,
                'position' => $payload['i'],
                'total' => $payload['n'],
            ];
        }
        $this->report->addNote(sprintf('%d events in store across %d streams', $numberOfEvents, count($this->eventsByStream)));
    }

    // --- Checks -----

    /**
     * Every logged success has to be present in full, in the layout it declared, ending at the versions
     * the store itself reported back
     */
    private function checkSuccessesArePresent(): void
    {
        foreach ($this->attemptsByCommitId as $commitId => $attempt) {
            if ($attempt->outcome !== Outcome::SUCCESS) {
                continue;
            }
            $found = $this->eventsByCommitId[$commitId] ?? [];
            if ($found === []) {
                $this->report->addViolation('PHANTOM_SUCCESS', sprintf('%s – but none of its %d events are in the store', $attempt->toDebugString(), count($attempt->eventIds)));
                continue;
            }
            if (count($found) !== count($attempt->eventIds)) {
                $this->report->addViolation('PARTIAL_WRITE', sprintf('%s – %d of %d events are in the store', $attempt->toDebugString(), count($found), count($attempt->eventIds)));
                continue;
            }

            usort($found, static fn (array $left, array $right) => $left['position'] <=> $right['position']);

            $expectedEventIds = $attempt->eventIds;
            $expectedStreams = self::expandSegments($attempt);
            $previousSequenceNumber = 0;
            foreach ($found as $index => $event) {
                if ($event['eventId'] !== ($expectedEventIds[$index] ?? null)) {
                    $this->report->addViolation('EVENT_ID_MISMATCH', sprintf('%s – event %d of the commit is "%s" but "%s" was written', $attempt->toDebugString(), $index + 1, $expectedEventIds[$index] ?? '(none)', $event['eventId']));
                }
                if ($event['stream'] !== ($expectedStreams[$index] ?? null)) {
                    $this->report->addViolation('SEGMENT_MISMATCH', sprintf('%s – event %d belongs in stream "%s" but was written to "%s"', $attempt->toDebugString(), $index + 1, $expectedStreams[$index] ?? '(none)', $event['stream']));
                }
                if ($event['sequenceNumber'] <= $previousSequenceNumber) {
                    $this->report->addViolation('COMMIT_ORDER_MISMATCH', sprintf('%s – event %d has sequence number %d which does not follow the previous event of the same commit (%d)', $attempt->toDebugString(), $index + 1, $event['sequenceNumber'], $previousSequenceNumber));
                }
                $previousSequenceNumber = $event['sequenceNumber'];
            }

            // versions have to continue across segments of the same stream rather than restart per segment
            $versionsPerStream = [];
            foreach ($found as $event) {
                $versionsPerStream[$event['stream']][] = $event['version'];
            }
            foreach ($versionsPerStream as $streamName => $versions) {
                foreach ($versions as $index => $version) {
                    if ($index > 0 && $version !== $versions[$index - 1] + 1) {
                        $this->report->addViolation('COMMIT_VERSION_GAP', sprintf('%s – versions written to stream "%s" are not consecutive: %s', $attempt->toDebugString(), $streamName, implode(', ', $versions)));
                        break;
                    }
                }
                $reported = $attempt->resultVersions[$streamName] ?? null;
                $actual = $versions[count($versions) - 1];
                if ($reported !== null && $reported !== $actual) {
                    $this->report->addViolation('RESULT_VERSION_MISMATCH', sprintf('%s – reported version %d for stream "%s" but the last event of that stream is at version %d', $attempt->toDebugString(), $reported, $streamName, $actual));
                }
            }
        }
    }

    /**
     * A rejected commit is only correct if it is also atomic: nothing at all may have been written
     */
    private function checkFailuresWroteNothing(): void
    {
        foreach ($this->attemptsByCommitId as $commitId => $attempt) {
            if ($attempt->outcome === Outcome::SUCCESS) {
                continue;
            }
            $found = $this->eventsByCommitId[$commitId] ?? [];
            if ($found === []) {
                continue;
            }
            $this->report->addViolation('NON_ATOMIC_ROLLBACK', sprintf(
                '%s – but %d of its %d events are in the store (e.g. "%s" in stream "%s" at version %d)',
                $attempt->toDebugString(),
                count($found),
                count($attempt->eventIds),
                $found[0]['eventId'],
                $found[0]['stream'],
                $found[0]['version'],
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
        foreach ($this->attemptsByCommitId as $commitId => $attempt) {
            if ($attempt->outcome !== Outcome::SUCCESS) {
                continue;
            }
            $found = $this->eventsByCommitId[$commitId] ?? [];
            if ($found === []) {
                // a success that wrote nothing has no place in the order – already reported as PHANTOM_SUCCESS
                continue;
            }
            $sequenceNumber = min(array_map(static fn (array $event) => $event['sequenceNumber'], $found));
            foreach ($attempt->constraints as $constraint) {
                $maybeVersion = $this->versionBefore($constraint->streamName->value, $sequenceNumber);
                if ($constraint->isSatisfiedBy($maybeVersion)) {
                    continue;
                }
                $this->report->addViolation('STALE_CONSTRAINT_ACCEPTED', sprintf(
                    '%s – but stream "%s" was at %s by the time the commit landed (sequence number %d)',
                    $attempt->toDebugString(),
                    $constraint->streamName->value,
                    $maybeVersion->isNothing() ? 'no version at all' : 'version ' . $maybeVersion->unwrap()->value,
                    $sequenceNumber,
                ));
            }
        }
    }

    /**
     * The version a stream had strictly before the given sequence number
     */
    private function versionBefore(string $streamName, int $sequenceNumber): MaybeVersion
    {
        $events = $this->eventsByStream[$streamName] ?? [];
        // the events of a stream are collected in ascending sequence number order, so the last one below
        // the given sequence number is a binary search rather than a scan of a stream of any size
        $low = 0;
        $high = count($events) - 1;
        $version = null;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            if ($events[$middle]['sequenceNumber'] >= $sequenceNumber) {
                $high = $middle - 1;
                continue;
            }
            $version = Version::fromInteger($events[$middle]['version']);
            $low = $middle + 1;
        }
        return MaybeVersion::fromVersionOrNull($version);
    }

    /**
     * Independent of any commit: a stream has to be versions 0..n-1, ascending with the sequence number
     */
    private function checkStreamVersionsAreContiguous(): void
    {
        foreach ($this->eventsByStream as $streamName => $events) {
            foreach ($events as $index => $event) {
                if ($event['version'] === $index) {
                    continue;
                }
                $this->report->addViolation('VERSION_GAP', sprintf(
                    'Event "%s" is number %d in stream "%s" (sequence number %d) so it should have version %d but has %d',
                    $event['eventId'],
                    $index + 1,
                    $streamName,
                    $event['sequenceNumber'],
                    $index,
                    $event['version'],
                ));
                break;
            }
        }
    }

    /**
     * The verdict checks: what the generator was able to prove before the attempt ran
     */
    private function checkVerdicts(): void
    {
        foreach ($this->attemptsByCommitId as $attempt) {
            if ($attempt->outcome === Outcome::UNEXPECTED_ERROR) {
                $this->report->addViolation('UNEXPECTED_ERROR', $attempt->toDebugString());
                continue;
            }
            if ($attempt->verdict === Verdict::MUST_FAIL && $attempt->outcome === Outcome::SUCCESS) {
                $this->report->addViolation('MUST_FAIL_SUCCEEDED', sprintf('%s – %s', $attempt->toDebugString(), $attempt->verdictReason));
                continue;
            }
            if (!$this->manifest->assertMustSucceedIsNotRejected) {
                continue;
            }
            if ($attempt->verdict === Verdict::MUST_SUCCEED && $attempt->outcome === Outcome::REJECTED) {
                $this->report->addViolation('MUST_SUCCEED_REJECTED', sprintf('%s – %s', $attempt->toDebugString(), $attempt->verdictReason));
            }
        }
    }

    /**
     * Guards against a degenerate run: a store that rejects (or swallows) everything must not pass, and a
     * shape that never actually got exercised must not be mistaken for a shape that passed
     */
    private function checkCoverageAndLiveness(): void
    {
        $shapeCounts = $this->report->shapeCounts();
        foreach (AttemptShape::all() as $shape) {
            $total = $shapeCounts[$shape->value]['total'] ?? 0;
            if ($total < $this->manifest->minAttemptsPerShape) {
                $this->report->addViolation('UNDER_COVERED_SHAPE', sprintf('Shape "%s" was attempted %d times, expected at least %d', $shape->value, $total, $this->manifest->minAttemptsPerShape));
            }
        }
        $satisfiable = $this->report->totalSatisfiable();
        if ($satisfiable === 0) {
            return;
        }
        $successRate = $this->report->totalSuccesses() / $satisfiable;
        if ($successRate < $this->manifest->minSuccessRate) {
            $this->report->addViolation('LOW_SUCCESS_RATE', sprintf('Only %.1f%% of the %d attempts that were not doomed by construction succeeded, expected at least %.1f%%', 100 * $successRate, $satisfiable, 100 * $this->manifest->minSuccessRate));
        }
        $emptyStreams = [];
        foreach ($this->manifest->streamNames() as $streamName) {
            if (!isset($this->eventsByStream[$streamName->value])) {
                $emptyStreams[] = $streamName->value;
            }
        }
        if ($emptyStreams !== []) {
            $this->report->addNote(sprintf('%d of %d streams received no events at all', count($emptyStreams), $this->manifest->numberOfStreams));
        }
    }

    // --- Helpers -----

    /**
     * The stream each event of the commit was supposed to end up in, in commit order
     *
     * @return list<string>
     */
    private static function expandSegments(OpLogEntry $entry): array
    {
        $streams = [];
        foreach ($entry->segments as $segment) {
            for ($i = 0; $i < $segment['count']; $i++) {
                $streams[] = $segment['stream'];
            }
        }
        return $streams;
    }

    /**
     * @return array{r: string, c: string, i: int, n: int}|null
     */
    private static function decodePayload(string $data): ?array
    {
        try {
            $payload = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($payload)) {
            return null;
        }
        if (!isset($payload['r'], $payload['c'], $payload['i'], $payload['n'])) {
            return null;
        }
        if (!is_string($payload['r']) || !is_string($payload['c']) || !is_int($payload['i']) || !is_int($payload['n'])) {
            return null;
        }
        return ['r' => $payload['r'], 'c' => $payload['c'], 'i' => $payload['i'], 'n' => $payload['n']];
    }
}
