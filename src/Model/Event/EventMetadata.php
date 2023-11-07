<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\Event;

use Webmozart\Assert\Assert;

final class EventMetadata
{
    /**
     * @param array<mixed> $value
     */
    private function __construct(
        public readonly array $value,
    ) {
    }

    /**
     * @param array<mixed> $value
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
            /** @var array<mixed> $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Failed to decode metadata from JSON: %s', $json), 1651749503, $e);
        }
        Assert::isArray($decoded, 'Metadata has to be encoded as array, given');
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
