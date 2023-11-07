<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\Event;

use Webmozart\Assert\Assert;

/**
 * The stream name can be any (non-empty) string
 */
final class StreamName
{
    /**
     * @var array<string, self>
     */
    private static array $instances = [];

    private function __construct(
        public readonly string $value
    ) {
    }

    private static function constant(string $value): self
    {
        return self::$instances[$value] ?? self::$instances[$value] = new self($value);
    }

    public static function fromString(string $value): self
    {
        Assert::stringNotEmpty($value, 'The stream name must not be empty');
        return self::constant($value);
    }

    public function equals(self $other): bool
    {
        return $other->value === $this->value;
    }
}
