<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

/**
 * Every attempt of a run, as recorded by the worker processes, indexed by commit id
 *
 * @implements \IteratorAggregate<int, OpLogEntry>
 */
final readonly class Attempts implements \IteratorAggregate, \Countable
{
    /**
     * @param array<string, OpLogEntry> $items
     */
    private function __construct(
        private array $items,
    ) {
    }

    /**
     * Reads the op logs of all workers of a run
     *
     * A commit id that was logged twice is kept only once and reported: everything the validator knows
     * about a commit is keyed by it, so a duplicate would make the rest of the report ambiguous.
     */
    public static function fromOpLogs(RunManifest $manifest, ValidationReport $report): self
    {
        $items = [];
        foreach (OpLog::readAll($manifest) as $entry) {
            if (isset($items[$entry->commitId])) {
                $report->addViolation(Violation::DUPLICATE_COMMIT_ID, sprintf('commitId "%s" was logged more than once', $entry->commitId));
                continue;
            }
            $items[$entry->commitId] = $entry;
            $report->recordAttempt($entry);
        }
        if ($items === []) {
            $report->addViolation(Violation::NO_ATTEMPTS, sprintf('No op log entries found in "%s" – did the write phase run?', $manifest->directory));
        }
        return new self($items);
    }

    public function has(string $commitId): bool
    {
        return isset($this->items[$commitId]);
    }

    public function getIterator(): \Traversable
    {
        yield from array_values($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
}
