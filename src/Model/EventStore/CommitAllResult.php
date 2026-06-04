<?php
namespace Neos\EventStore\Model\EventStore;

use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\Event\Version;

/**
 * Result for EventStoreInterface::commitAll
 */
final class CommitAllResult
{
    /**
     * @param array<VersionForStream> $versionForStream
     */
    private function __construct(
        public readonly SequenceNumber $highestCommittedSequenceNumber,
        public readonly array $versionsForStream
    ) {}

    public static function create(SequenceNumber $highestCommittedSequenceNumber, VersionForStream ...$versionsForStream): self
    {
        return new self($highestCommittedSequenceNumber, $versionsForStream);
    }
}
