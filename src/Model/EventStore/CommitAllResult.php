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
    private function __construct(
        public readonly SequenceNumber $highestCommittedSequenceNumber,
        public readonly VersionForStreams $versionForStreams
    ) {
    }

    public static function create(SequenceNumber $highestCommittedSequenceNumber, VersionForStreams $versionForStreams): self
    {
        return new self($highestCommittedSequenceNumber, $versionForStreams);
    }

    /** @internal */
    public function first(): CommitResult
    {
        return new CommitResult(
            highestCommittedVersion: $this->versionForStreams->items[0]->version,
            highestCommittedSequenceNumber: $this->highestCommittedSequenceNumber,
        );
    }
}
