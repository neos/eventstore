<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\Event;

/**
 * The type of event, for example "CustomerHasSignedUp"
 *
 * @api
 */
final class EventType
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
