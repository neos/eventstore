<?php
declare(strict_types=1);
namespace Neos\EventStore\CatchUp;

use Neos\EventStore\DoctrineAdapter\DoctrineCheckpointStorage;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\EventEnvelope;
use Neos\EventStore\Model\EventStream\EventStreamInterface;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * This helper class is typically used inside Projections to implement the orchestration of projection catch up; together
 * with {@see CheckpointStorageInterface}.
 *
 * It ensures that a given projection **never runs concurrently** and thus prevents race conditions where the same
 * projector is accidentally running multiple times in parallel.
 *
 * If you use the {@see DoctrineCheckpointStorage}, and share the same database connection with your projection,
 * this class **implements Exactly-Once Semantics for your projections**, to ensure each event is seen
 * EXACTLY once in your projection.
 *
 * ## How does it work?
 *
 * When you call {@see CatchUp::run()}, a lock is acquired via {@see CheckpointStorageInterface::acquireLock()}
 * (e.g. from the database), to ensure we run only once (even if in a distributed system). For relational
 * databases, this also starts a database transaction.
 *
 * After every batchSize events (typically after every event), we update the sequence number and commit
 * the transaction (via {@see CheckpointStorageInterface::updateAndReleaseLock()}). Then, we open a new transaction.
 *
 * In case of errors, we rollback the transaction.
 *
 * TODO: can you use own transactions in your projection code? I (SK) am currently not sure about this.
 *
 * ## Example Usage (inside your projection)
 *
 * ```php
 * public function catchUp(EventStreamInterface $eventStream): void
 * {
 *     $catchUp = CatchUp::create($this->apply(...), $this->checkpointStorage);
 *     $catchUp->run($eventStream);
 * }
 * ```
 *
 * the callback (`$this->apply(...)` in the above example) is called with the {@see EventEnvelope} instance,
 * once for each event.
 *
 * ## Example Projection Skeleton
 *
 * ```php
 * use Neos\EventStore\CatchUp\CatchUp;
 * use Neos\EventStore\CatchUp\CheckpointStorageInterface;
 * use Neos\EventStore\Model\Event;
 * use Neos\EventStore\Model\EventEnvelope;
 * use Neos\EventStore\Model\EventStream\EventStreamInterface;
 * use Neos\EventStore\Model\Event\SequenceNumber;
 *
 * final class MyProjection implements ProjectionInterface
 * {
 *     public function __construct(
 *         // NOTE: we suggest that you add an EventNormalizer to
 *         // your project for encoding/decoding projects (see boilerplate).
 *         private readonly CheckpointStorageInterface $checkpointStorage
 *     ) {}
 *
 *     public function canHandle(Event $event): bool
 *     {
 *         return method_exists($this, 'when' . $event->type->value);
 *     }
 *
 *     public function catchUp(EventStreamInterface $eventStream): void
 *     {
 *         $catchUp = CatchUp::create($this->apply(...), $this->checkpointStorage);
 *         $catchUp->run($eventStream);
 *     }
 *
 *     private function apply(EventEnvelope $eventEnvelope): void
 *     {
 *         if (!$this->canHandle($eventEnvelope->event)) {
 *             return;
 *         }
 *         $eventInstance = $this->eventNormalizer->denormalize($eventEnvelope->event);
 *         $this->{'when' . $eventEnvelope->event->type->value}($eventInstance);
 *     }
 *
 *     public function getSequenceNumber(): SequenceNumber
 *     {
 *         return $this->checkpointStorage->getHighestAppliedSequenceNumber();
 *     }
 * }
 * ```
 */
final class CatchUp
{
    private function __construct(
        private readonly \Closure $eventHandler,
        private readonly CheckpointStorageInterface $checkpointStorage,
        private readonly int $batchSize,
        private readonly ?\Closure $onBeforeBatchCompletedHook,
    ) {
        Assert::positiveInteger($batchSize);
    }

    public static function create(\Closure $eventApplier, CheckpointStorageInterface $checkpointStorage): self
    {
        return new self($eventApplier, $checkpointStorage, 1, null);
    }

    /**
     * After how many events should the (database) transaction be committed?
     *
     * @param int $batchSize
     * @return $this
     */
    public function withBatchSize(int $batchSize): self
    {
        if ($batchSize === $this->batchSize) {
            return $this;
        }
        return new self($this->eventHandler, $this->checkpointStorage, $batchSize, $this->onBeforeBatchCompletedHook);
    }

    /**
     * This hook is called directly before the sequence number is persisted back in CheckpointStorage.
     * Use this to trigger any operation which need to happen BEFORE the sequence number update is made
     * visible to the outside.
     *
     * Overrides all previously registered onBeforeBatchCompleted hooks.
     *
     * @param Closure $callback the hook being called before the batch is completed
     * @return $this
     */
    public function withOnBeforeBatchCompleted(\Closure $callback): self
    {
        return new self($this->eventHandler, $this->checkpointStorage, $this->batchSize, $callback);
    }

    public function run(EventStreamInterface $eventStream): SequenceNumber
    {
        $highestAppliedSequenceNumber = $this->checkpointStorage->acquireLock();
        $iteration = 0;
        try {
            foreach ($eventStream->withMinimumSequenceNumber($highestAppliedSequenceNumber->next()) as $event) {
                if ($event->sequenceNumber->value <= $highestAppliedSequenceNumber->value) {
                    continue;
                }
                ($this->eventHandler)($event);
                $iteration ++;
                if ($this->batchSize === 1 || $iteration % $this->batchSize === 0) {
                    if ($this->onBeforeBatchCompletedHook) {
                        ($this->onBeforeBatchCompletedHook)();
                    }
                    $this->checkpointStorage->updateAndReleaseLock($event->sequenceNumber);
                    $highestAppliedSequenceNumber = $this->checkpointStorage->acquireLock();
                } else {
                    $highestAppliedSequenceNumber = $event->sequenceNumber;
                }
            }
        } finally {
            try {
                if ($this->onBeforeBatchCompletedHook) {
                    ($this->onBeforeBatchCompletedHook)();
                }
            } catch (Throwable $e) {
                $this->checkpointStorage->updateAndReleaseLock($highestAppliedSequenceNumber);
                throw $e;
            }
            $this->checkpointStorage->updateAndReleaseLock($highestAppliedSequenceNumber);
        }
        return $highestAppliedSequenceNumber;
    }
}
