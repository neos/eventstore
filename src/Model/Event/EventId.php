<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\Event;

use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Globally unique id of an event, usually in the form of a UUID
 *
 * @api
 */
final class EventId
{
    public const MAX_LENGTH = 36;

    private function __construct(
        public readonly string $value,
    ) {
        Assert::stringNotEmpty($value, 'The event id must not be empty');
        Assert::maxLength($value, self::MAX_LENGTH, 'The event id must not exceed ' . self::MAX_LENGTH . ' characters');
    }

    public static function create(): self
    {
        return new self(Uuid::uuid4()->toString());
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }
}
