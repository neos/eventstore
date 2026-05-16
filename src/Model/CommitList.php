<?php
declare(strict_types=1);

namespace Neos\EventStore\Model;

use Neos\EventStore\Exception\InvalidCommitListException;
use Neos\Flow\Annotations as Flow;
use Traversable;

/**
 * @implements \IteratorAggregate<Commit>
 */
final readonly class CommitList implements \IteratorAggregate
{
    /** @var non-empty-list<Commit> $items */
    private function __construct(
        public array $items
    ) {
        $streamsToWrite = [];
        foreach ($this->items as $commit) {
            if (array_key_exists($commit->streamName->value, $streamsToWrite)) {
                throw new \InvalidArgumentException(sprintf('More than one commit registered for stream "%s". Commits must be issued compacted.', $commit->streamName->value), 1778940402);
            }
            $streamsToWrite[$commit->streamName->value] = true;
        }
    }

    public static function create(Commit $commit, Commit ...$commits): self
    {
        // TODO ensure one possible commit per stream?!
        return new self([$commit, ...array_values($commits)]);
    }

    public function getIterator(): Traversable
    {
        yield from $this->items;
    }
}
