<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\Event;

use Webmozart\Assert\Assert;

/**
 * An optional identifier that allows to correlate events
 * This can be a random identifier or some id of the domain (e.g. a shopping cart id)
 * @api
 */
final class CorrelationId
{
    public const MAX_LENGTH = 40;

    private function __construct(
        public readonly string $value,
    ) {
        Assert::maxLength($value, self::MAX_LENGTH, 'The correlation id must not exceed ' . self::MAX_LENGTH . ' characters');
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }
}
