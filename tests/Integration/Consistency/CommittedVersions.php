<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Integration\Consistency;

use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Event\Version;
use Neos\EventStore\Model\EventStore\CommitAllResult;
use Neos\EventStore\Model\EventStore\VersionForStream;

/**
 * The per-stream versions the store reported back for a commit, keyed by stream name
 *
 * Empty for an attempt that did not succeed. Logging what the store *claimed* – rather than only what
 * ended up in the store – is what allows the validator to check the two against each other.
 *
 * @implements \IteratorAggregate<int, VersionForStream>
 */
final readonly class CommittedVersions implements \IteratorAggregate, \Countable
{
    /**
     * @param array<string, VersionForStream> $items
     */
    private function __construct(
        private array $items,
    ) {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function create(VersionForStream ...$items): self
    {
        $indexed = [];
        foreach ($items as $item) {
            $indexed[$item->streamName->value] = $item;
        }
        return new self($indexed);
    }

    public static function forStream(StreamName $streamName, Version $version): self
    {
        return self::create(VersionForStream::create($streamName, $version));
    }

    public static function fromCommitAllResult(CommitAllResult $result): self
    {
        return self::create(...iterator_to_array($result->versionForStreams));
    }

    /**
     * @param list<Json> $jsonList
     */
    public static function fromJsonList(array $jsonList): self
    {
        return self::create(...array_map(
            static fn (Json $json) => VersionForStream::create(
                StreamName::fromString($json->string('stream')),
                Version::fromInteger($json->integer('version')),
            ),
            $jsonList,
        ));
    }

    public function versionFor(StreamName $streamName): ?Version
    {
        return ($this->items[$streamName->value] ?? null)?->version;
    }

    /**
     * @return list<array{stream: string, version: int}>
     */
    public function toArray(): array
    {
        return array_values(array_map(
            static fn (VersionForStream $item) => ['stream' => $item->streamName->value, 'version' => $item->version->value],
            $this->items,
        ));
    }

    public function getIterator(): \Traversable
    {
        yield from array_values($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
}
