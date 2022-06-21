<?php
declare(strict_types=1);
namespace Neos\EventStore\CatchUp;

use Neos\EventStore\Model\Event\SequenceNumber;

/**
 * See {@see CatchUp} for an explanation what this class does in detail.
 */
interface CheckpointStorageInterface
{
    public function acquireLock(): SequenceNumber;
    public function updateAndReleaseLock(SequenceNumber $sequenceNumber): void;
    public function getHighestAppliedSequenceNumber(): SequenceNumber;
}
