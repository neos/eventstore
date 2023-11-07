<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\Event;

/**
 * The actual payload of an event, usually serialized as JSON
 *
 * @api
 */
final class EventData
{
    private function __construct(
        public readonly string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }
}
