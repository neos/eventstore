<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\EventStore;

use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\Event\Version;

final class CommitResult
{
    public function __construct(
        public readonly Version        $highestCommittedVersion,
        public readonly SequenceNumber $highestCommittedSequenceNumber,
    ) {
    }
}
