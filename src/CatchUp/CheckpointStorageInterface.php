<?php
declare(strict_types=1);
namespace Neos\EventStore\CatchUp;

use Neos\EventStore\Model\Event\SequenceNumber;

/**
 * Contract for a central authority that keeps track of which event has been processed by a single event listener to prevent
 * the same event to be applied multiple times.
 *
 * Implementations of this interface should start an exclusive lock with {@see self::acquireLock()} in order to prevent a
 * separate instance (potentially in a separate process) to return the same {@see SequenceNumber}.
 *
 * An instance of this class is always ever responsible for a single event handler.
 * If both, the event handler and its checkpoint storage, use the same backend (for example the same database connection)
 * to manage their state, Exactly-Once Semantics can be guaranteed.
 *
 * See {@see CatchUp} for an explanation what this class does in detail.
 * @api
 */
interface CheckpointStorageInterface
{
    /**
     * Obtain an exclusive lock (to prevent multiple instances from being executed simultaneously)
     * and return the highest {@see SequenceNumber} that was processed by this checkpoint storage.
     *
     * @return SequenceNumber The sequence number that was previously set via {@see updateAndReleaseLock()} or SequenceNumber(0) if it was not updated before
     */
    public function acquireLock(): SequenceNumber;

    /**
     * Store the new {@see SequenceNumber} and release the lock
     *
     * @param SequenceNumber $sequenceNumber The sequence number to store – usually after the corresponding event was processed by a listener or when a projection was reset
     */
    public function updateAndReleaseLock(SequenceNumber $sequenceNumber): void;

    /**
     * @return SequenceNumber the last {@see SequenceNumber} that was set via {@see updateAndReleaseLock()} without acquiring a lock
     */
    public function getHighestAppliedSequenceNumber(): SequenceNumber;
}
