<?php
namespace Neos\EventStore\Model\EventStore;

use Neos\EventStore\Model\Event\SequenceNumber;

/**
 * Result for EventStoreInterface::commitAll
 */
final class CommitAllResult
{
    private function __construct(
        public readonly SequenceNumber $highestCommittedSequenceNumber,
        public readonly VersionForStreams $versionForStreams
    ) {
    }

    public static function create(SequenceNumber $highestCommittedSequenceNumber, VersionForStreams $versionForStreams): self
    {
        return new self($highestCommittedSequenceNumber, $versionForStreams);
    }
}
