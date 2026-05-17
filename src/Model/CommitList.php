<?php
declare(strict_types=1);

namespace Neos\EventStore\Model;

/**
 * @implements \IteratorAggregate<Commit>
 */
final readonly class CommitList implements \IteratorAggregate, \Countable
{
    /** @var non-empty-list<Commit> $items */
    private function __construct(
        public array $items
    ) {
    }

    public static function create(Commit $commit, Commit ...$commits): self
    {
        return new self([$commit, ...array_values($commits)]);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }
}
