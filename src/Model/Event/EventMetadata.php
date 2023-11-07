<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\Event;

use Neos\EventStore\Model\EventStream\VirtualStreamType;
use Webmozart\Assert\Assert;

/**
 * Arbitrary metadata that can be attached to events, serialized as JSON
 *
 * *Note:* The values can consist of a dictionary with arbitrary string-keys and any value that can be JSON encoded
 * but the keys "correlationId" and "causationId" have a special meaning (@see VirtualStreamType::CORRELATION_ID})
 *
 * @api
 */
final class EventMetadata
{
    /**
     * @param array<string, mixed> $value
     */
    private function __construct(
        public readonly array $value,
    ) {
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        return new self($value);
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Failed to decode metadata from JSON: %s', $json), 1651749503, $e);
        }
        Assert::isArray($decoded, 'Metadata has to be encoded as array, given');
        Assert::isMap($decoded, 'Metadata has to be an associative array with string keys');
        return new self($decoded);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->value);
    }

    public function get(string $key): mixed
    {
        return $this->value[$key] ?? null;
    }

    public function toJson(): string
    {
        try {
            return json_encode($this->value, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Failed to encode metadata to JSON: %s', $e->getMessage()), 1651749485, $e);
        }
    }
}
