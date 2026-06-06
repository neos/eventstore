<?php
declare(strict_types=1);

namespace Neos\EventStore\Model\EventStream;

/**
 * @implements \IteratorAggregate<int,ExpectedVersionForStream>
 */
final readonly class ExpectedVersionForStreams implements \IteratorAggregate, \Countable
{
    /** @param non-empty-array<string,ExpectedVersionForStream> $items */
    private function __construct(
        private array $items
    ) {
    }

    public static function create(ExpectedVersionForStream $first, ExpectedVersionForStream ...$items): self
    {
        $indexed = [
            $first->streamName->value => $first
        ];

        foreach ($items as $item) {
            if (array_key_exists($item->streamName->value, $indexed)) {
                throw new \InvalidArgumentException(sprintf('Duplicate expected version %s defined for stream %s', $item->expectedVersion->__toString(), $item->streamName->value), 1780750216);
            }
            $indexed[$item->streamName->value] = $item;
        }

        return new self($indexed);
    }

    public function withAppended(ExpectedVersionForStream $item): self
    {
        if (array_key_exists($item->streamName->value, $this->items)) {
            throw new \InvalidArgumentException(sprintf('Duplicate expected version %s defined for stream %s', $item->expectedVersion->__toString(), $item->streamName->value), 1780752238);
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
        // TODO always use to debug string
        return sprintf('[%s]', join(', ', array_map(fn (ExpectedVersionForStream $item) => sprintf('%s: %s', $item->streamName->value, $item->expectedVersion->__toString()), $this->items)));
    }
}
