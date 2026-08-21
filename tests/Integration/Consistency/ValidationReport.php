<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

/**
 * Accumulates everything the validator found, so a run reports the whole picture rather than the first
 * assertion that happened to trip
 *
 * The difference between "one flaky edge case" and "every segmented commit is broken" is exactly the
 * information you need while reworking an adapter, and a fail-fast assertion destroys it.
 */
final class ValidationReport
{
    private const MAX_EXAMPLES_PER_VIOLATION = 5;

    /** @var array<string, int> number of occurrences, keyed by {@see Violation} */
    private array $violationCounts = [];

    /** @var array<string, list<string>> the first few details, keyed by {@see Violation} */
    private array $violationExamples = [];

    /** @var array<string, OutcomeCounts> keyed by {@see AttemptShape} */
    private array $countsByShape = [];

    /** @var list<string> */
    private array $notes = [];

    /**
     * Attempts that were not doomed by construction – the denominator of the liveness check
     */
    private int $satisfiableAttempts = 0;

    public function __construct(
        private readonly RunManifest $manifest,
    ) {
    }

    public function addViolation(Violation $violation, string $detail): void
    {
        $this->violationCounts[$violation->value] = ($this->violationCounts[$violation->value] ?? 0) + 1;
        if (count($this->violationExamples[$violation->value] ?? []) < self::MAX_EXAMPLES_PER_VIOLATION) {
            $this->violationExamples[$violation->value][] = $detail;
        }
    }

    public function recordAttempt(OpLogEntry $entry): void
    {
        $this->countsForShape($entry->shape)->record($entry->result->outcome);
        if ($entry->judgement->verdict !== Verdict::MUST_FAIL) {
            $this->satisfiableAttempts++;
        }
    }

    public function addNote(string $note): void
    {
        $this->notes[] = $note;
    }

    public function hasViolations(): bool
    {
        return $this->violationCounts !== [];
    }

    public function countsForShape(AttemptShape $shape): OutcomeCounts
    {
        return $this->countsByShape[$shape->value] ??= OutcomeCounts::none();
    }

    public function totalAttempts(): int
    {
        $total = 0;
        foreach ($this->countsByShape as $counts) {
            $total += $counts->total();
        }
        return $total;
    }

    /**
     * Attempts whose constraints were not already provably unsatisfiable when they were generated
     *
     * Roughly a third of the catalogue is designed to always be rejected, so measuring liveness against
     * *all* attempts would track the shape mix rather than the health of the store.
     */
    public function totalSatisfiable(): int
    {
        return $this->satisfiableAttempts;
    }

    public function totalSuccesses(): int
    {
        $total = 0;
        foreach ($this->countsByShape as $counts) {
            $total += $counts->successes();
        }
        return $total;
    }

    public function digest(): string
    {
        $lines = [];
        $lines[] = '';
        $lines[] = sprintf('Consistency run "%s" (profile: %s)', $this->manifest->runId, $this->manifest->profile->value);
        $lines[] = str_repeat('=', 78);
        $lines[] = '';
        $lines[] = sprintf('%-32s %7s %7s %9s %7s', 'SHAPE', 'total', 'ok', 'rejected', 'error');
        $lines[] = str_repeat('-', 78);
        foreach (AttemptShape::all() as $shape) {
            $counts = $this->countsForShape($shape);
            $lines[] = sprintf(
                '%-32s %7d %7d %9d %7d%s',
                $shape->value,
                $counts->total(),
                $counts->successes(),
                $counts->rejections(),
                $counts->errors(),
                $counts->total() < $this->manifest->minAttemptsPerShape ? '  << under-covered' : '',
            );
        }
        $lines[] = str_repeat('-', 78);
        $lines[] = sprintf('%-32s %7d %7d', 'TOTAL', $this->totalAttempts(), $this->totalSuccesses());
        $lines[] = sprintf(
            '%-32s %7d %7d %8s',
            '  of which satisfiable',
            $this->totalSatisfiable(),
            $this->totalSuccesses(),
            $this->totalSatisfiable() === 0 ? 'n/a' : sprintf('%.1f%%', 100 * $this->totalSuccesses() / $this->totalSatisfiable()),
        );
        $lines[] = '';

        foreach ($this->notes as $note) {
            $lines[] = '  note: ' . $note;
        }
        if ($this->notes !== []) {
            $lines[] = '';
        }

        if (!$this->hasViolations()) {
            $lines[] = 'No violations.';
            $lines[] = '';
            return implode(chr(10), $lines);
        }

        $lines[] = sprintf('%-32s %7s', 'VIOLATIONS', 'count');
        $lines[] = str_repeat('-', 78);
        arsort($this->violationCounts);
        foreach ($this->violationCounts as $violation => $count) {
            $lines[] = sprintf('%-32s %7d', $violation, $count);
            foreach ($this->violationExamples[$violation] ?? [] as $example) {
                $lines[] = '    ' . $example;
            }
            $remaining = $count - count($this->violationExamples[$violation] ?? []);
            if ($remaining > 0) {
                $lines[] = sprintf('    … and %d more', $remaining);
            }
        }
        $lines[] = str_repeat('-', 78);
        $lines[] = sprintf('Run kept at: %s', $this->manifest->directory);
        $lines[] = '';
        return implode(chr(10), $lines);
    }

    public function summary(): string
    {
        $violations = [];
        foreach ($this->violationCounts as $violation => $count) {
            $violations[] = sprintf('%s×%d', $violation, $count);
        }
        return sprintf(
            'Consistency run "%s" failed with %d violation(s): %s',
            $this->manifest->runId,
            array_sum($this->violationCounts),
            implode(', ', $violations),
        );
    }
}
