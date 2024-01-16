<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\EventStream;

use Neos\EventStore\Model\Event\CorrelationId;
use Webmozart\Assert\Assert;

/**
 * A Virtual stream name synthesize multiple streams and can only be used to _read_ events.
 * Internally, virtual stream names start with a "$"
 * @api
 */
final class VirtualStreamName
{
    /**
     * @var array<string, self>
     */
    private static array $instances = [];

    private function __construct(
        public readonly VirtualStreamType $type,
        public readonly string $value,
    ) {
    }

    private static function constant(VirtualStreamType $type, string $value): self
    {
        $id = $type->value . '_' . $value;
        return self::$instances[$id] ?? self::$instances[$id] = new self($type, $value);
    }

    public static function forCategory(string $categoryName): self
    {
        Assert::stringNotEmpty($categoryName);
        return self::constant(VirtualStreamType::CATEGORY, $categoryName);
    }

    public static function forCorrelationId(CorrelationId $correlationId): self
    {
        Assert::stringNotEmpty($correlationId);
        return self::constant(VirtualStreamType::CORRELATION_ID, $correlationId->value);
    }

    public static function all(): self
    {
        return self::constant(VirtualStreamType::ALL, '');
    }
}
