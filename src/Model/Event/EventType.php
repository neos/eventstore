<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\Event;

use Webmozart\Assert\Assert;

/**
 * The type of event, for example "CustomerHasSignedUp"
 *
 * @api
 */
final class EventType
{
    public const MAX_LENGTH = 100;

    private function __construct(
        public readonly string $value,
    ) {
        Assert::stringNotEmpty($value, 'The event type must not be empty');
        Assert::maxLength($value, self::MAX_LENGTH, 'The event type name must not exceed ' . self::MAX_LENGTH . ' characters');
        Assert::regex($value, '/^[[:ascii:]]+$/', 'The event type must only contain ASCII characters, given: %s');
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }
}
