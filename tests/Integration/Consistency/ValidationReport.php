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
    private const MAX_EXAMPLES_PER_CLASS = 5;

    /** @var array<string, int> */
    private array $violationCounts = [];

    /** @var array<string, list<string>> */
    private array $violationExamples = [];

    /** @var array<string, array{total: int, success: int, rejected: int, error: int}> */
    private array $shapeCounts = [];

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

    public function addViolation(string $class, string $detail): void
    {
        $this->violationCounts[$class] = ($this->violationCounts[$class] ?? 0) + 1;
        if (count($this->violationExamples[$class] ?? []) < self::MAX_EXAMPLES_PER_CLASS) {
            $this->violationExamples[$class][] = $detail;
        }
    }

    public function recordAttempt(OpLogEntry $entry): void
    {
        $shape = $entry->shape->value;
        $this->shapeCounts[$shape] ??= ['total' => 0, 'success' => 0, 'rejected' => 0, 'error' => 0];
        $this->shapeCounts[$shape]['total']++;
        if ($entry->verdict !== Verdict::MUST_FAIL) {
            $this->satisfiableAttempts++;
        }
        $key = match ($entry->outcome) {
            Outcome::SUCCESS => 'success',
            Outcome::REJECTED => 'rejected',
            Outcome::UNEXPECTED_ERROR => 'error',
        };
        $this->shapeCounts[$shape][$key]++;
    }

    public function addNote(string $note): void
    {
        $this->notes[] = $note;
    }

    public function hasViolations(): bool
    {
        return $this->violationCounts !== [];
    }

    public function totalAttempts(): int
    {
        $total = 0;
        foreach ($this->shapeCounts as $counts) {
            $total += $counts['total'];
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
        foreach ($this->shapeCounts as $counts) {
            $total += $counts['success'];
        }
        return $total;
    }

    /**
     * @return array<string, array{total: int, success: int, rejected: int, error: int}>
     */
    public function shapeCounts(): array
    {
        return $this->shapeCounts;
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
            $counts = $this->shapeCounts[$shape->value] ?? ['total' => 0, 'success' => 0, 'rejected' => 0, 'error' => 0];
            $lines[] = sprintf(
                '%-32s %7d %7d %9d %7d%s',
                $shape->value,
                $counts['total'],
                $counts['success'],
                $counts['rejected'],
                $counts['error'],
                $counts['total'] < $this->manifest->minAttemptsPerShape ? '  << under-covered' : '',
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
        foreach ($this->violationCounts as $class => $count) {
            $lines[] = sprintf('%-32s %7d', $class, $count);
            foreach ($this->violationExamples[$class] ?? [] as $example) {
                $lines[] = '    ' . $example;
            }
            $remaining = $count - count($this->violationExamples[$class] ?? []);
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
        $classes = [];
        foreach ($this->violationCounts as $class => $count) {
            $classes[] = sprintf('%s×%d', $class, $count);
        }
        return sprintf(
            'Consistency run "%s" failed with %d violation(s): %s',
            $this->manifest->runId,
            array_sum($this->violationCounts),
            implode(', ', $classes),
        );
    }
}
