<?php
declare(strict_types=1);
namespace Neos\EventStore\Helper;

use Neos\EventStore\CatchUp\CheckpointStorageInterface;
use Neos\EventStore\Exception\CheckpointException;
use Neos\EventStore\Model\Event\SequenceNumber;

/**
 * In-memory implementation of a checkpoint storage
 *
 * @internal This helper is mostly useful for testing purposes and should not be used in production
 */
final class InMemoryCheckpointStorage implements CheckpointStorageInterface
{

    private SequenceNumber $sequenceNumber;

    /**
     * @var array<string, true>
     */
    private static array $activeTransactions = [];

    public function __construct(
        private readonly string $subscriptionId,
    ) {
        $this->sequenceNumber = SequenceNumber::none();
    }

    public function acquireLock(): SequenceNumber
    {
        if ($this->isTransactionActive()) {
            throw new CheckpointException(sprintf('Transaction for subscription "%s" is already active', $this->subscriptionId), 1660215484);
        }
        self::$activeTransactions[$this->subscriptionId] = true;
        return $this->sequenceNumber;
    }

    public function updateAndReleaseLock(SequenceNumber $sequenceNumber): void
    {
        if (!$this->isTransactionActive()) {
            throw new CheckpointException(sprintf('Transaction for subscription "%s" is not active', $this->subscriptionId), 1660215519);
        }
        $this->sequenceNumber = $sequenceNumber;
        unset(self::$activeTransactions[$this->subscriptionId]);
    }

    public function getHighestAppliedSequenceNumber(): SequenceNumber
    {
        return $this->sequenceNumber;
    }

    private function isTransactionActive(): bool
    {
        return array_key_exists($this->subscriptionId, self::$activeTransactions);
    }

    // phpcs:ignore
    public static function _resetTransactions(): void
    {
        self::$activeTransactions = [];
    }
}
