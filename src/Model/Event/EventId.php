<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\Event;

use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Globally unique id of an event, in the form of a UUID
 *
 * @api
 */
final class EventId
{
    public const MAX_LENGTH = 36;

    private function __construct(
        public readonly string $value,
    ) {
        Assert::length($value, self::MAX_LENGTH, 'The event id must be exactly ' . self::MAX_LENGTH . ' characters, got %s');
    }

    public static function create(): self
    {
        return new self(Uuid::uuid4()->toString());
    }

    public static function fromString(string $value): self
    {
        return new self(Uuid::fromString($value)->toString());
    }
}
