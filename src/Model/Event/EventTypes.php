<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\Event;

use ArrayIterator;
use IteratorAggregate;
use Traversable;
use Webmozart\Assert\Assert;

/**
 * @implements IteratorAggregate<EventType>
 */
final class EventTypes implements IteratorAggregate
{
    /**
     * @param EventType[] $types
     */
    private function __construct(
        public readonly array $types,
    ) {
        Assert::notEmpty($this->types, 'EventTypes must not be empty');
    }

    public static function create(EventType ...$types): self
    {
        return new self($types);
    }

    public function contains(EventType $type): bool
    {
        foreach ($this->types as $typesInSet) {
            if ($typesInSet->value === $type->value) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return string[]
     */
    public function toStringArray(): array
    {
        return array_map(static fn (EventType $type) => $type->value, $this->types);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->types);
    }
}
