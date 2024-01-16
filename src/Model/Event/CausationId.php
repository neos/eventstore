<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\Event;

use Webmozart\Assert\Assert;

/**
 * An optional identifier that points to the causation of an event
 * This can be the {@see EventId} of another event or some concept of the domain (e.g. a command identifier)
 * @api
 */
final class CausationId
{
    public const MAX_LENGTH = 40;

    private function __construct(
        public readonly string $value,
    ) {
        Assert::maxLength($value, self::MAX_LENGTH, 'The causation id must not exceed ' . self::MAX_LENGTH . ' characters');
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }
}
