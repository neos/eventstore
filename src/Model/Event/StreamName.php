<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\Event;

use Neos\EventStore\Model\EventStream\VirtualStreamName;
use Webmozart\Assert\Assert;

/**
 * The stream name can be any (non-empty) string
 *
 * @see VirtualStreamName
 * @api
 */
final class StreamName
{
    public const MAX_LENGTH = 100;

    /**
     * @var array<string, self>
     */
    private static array $instances = [];

    private function __construct(
        public readonly string $value
    ) {
        Assert::stringNotEmpty($value, 'The stream name must not be empty');
        Assert::maxLength($value, self::MAX_LENGTH, 'The stream name must not exceed ' . self::MAX_LENGTH . ' characters');
        Assert::regex($value, '/^[[:ascii:]]+$/', 'The stream name must only contain ASCII characters, given: %s');
    }

    private static function constant(string $value): self
    {
        return self::$instances[$value] ?? self::$instances[$value] = new self($value);
    }

    public static function fromString(string $value): self
    {
        return self::constant($value);
    }

    public function equals(self $other): bool
    {
        return $other->value === $this->value;
    }
}
