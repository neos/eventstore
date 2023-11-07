<?php
declare(strict_types=1);
namespace Neos\EventStore\Model\EventStore;

use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\Event\Version;

/**
 * The result of an {@see EventStoreInterface::commit()} call that contains information
 * about the commited {@see Version} and {@see SequenceNumber}
 * @api
 */
final class CommitResult
{
    public function __construct(
        public readonly Version $highestCommittedVersion,
        public readonly SequenceNumber $highestCommittedSequenceNumber,
    ) {
    }
}
