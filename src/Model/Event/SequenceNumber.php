<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\Event;

use Webmozart\Assert\Assert;

/**
 * The global sequence number of a persisted event
 * @api
 */
final readonly class SequenceNumber
{
    private function __construct(
        public int $value
    ) {
        Assert::natural($value, 'sequence number has to be a non-negative integer, got: %s');
    }

    public static function fromInteger(int $value): self
    {
        return new self($value);
    }

    public static function none(): self
    {
        return new self(0);
    }

    public function previous(): self
    {
        return new self($this->value - 1);
    }

    public function next(): self
    {
        return new self($this->value + 1);
    }

    public function equals(self $other): bool
    {
        return $other->value === $this->value;
    }
}
