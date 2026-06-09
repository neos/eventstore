<?php
declare(strict_types=1);

namespace Neos\EventStore\Model\EventStream;

/**
 * @implements \IteratorAggregate<int,ExpectedVersionForStream|ExpectedNoStream|ExpectedStreamExists>
 */
final readonly class ExpectedVersionForStreams implements \IteratorAggregate, \Countable
{
    /** @param array<string,ExpectedVersionForStream|ExpectedNoStream|ExpectedStreamExists> $items */
    private function __construct(
        private array $items
    ) {
    }

    public static function create(ExpectedVersionForStream|ExpectedNoStream|ExpectedStreamExists ...$items): self
    {
        $indexed = [];
        foreach ($items as $item) {
            if (array_key_exists($item->streamName->value, $indexed)) {
                throw new \InvalidArgumentException(sprintf('Duplicate constraint %s', $item->toDebugString()), 1780750216);
            }
            $indexed[$item->streamName->value] = $item;
        }

        return new self($indexed);
    }

    public function withAppended(ExpectedVersionForStream|ExpectedNoStream|ExpectedStreamExists $item): self
    {
        if (array_key_exists($item->streamName->value, $this->items)) {
            throw new \InvalidArgumentException(sprintf('Duplicate constraint %s', $item->toDebugString()), 1780752238);
        }
        return new self([...$this->items, ...[$item->streamName->value => $item]]);
    }

    public function getIterator(): \Traversable
    {
        yield from array_values($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function toDebugString(): string
    {
        return sprintf('[%s]', join(', ', array_map(fn (ExpectedVersionForStream|ExpectedNoStream|ExpectedStreamExists $item) => $item->toDebugString(), $this->items)));
    }
}
