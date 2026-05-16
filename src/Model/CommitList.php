<?php
declare(strict_types=1);

namespace Neos\EventStore\Model;

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
