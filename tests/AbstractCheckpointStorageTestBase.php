<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests;

use Neos\EventStore\CatchUp\CheckpointStorageInterface;
use Neos\EventStore\Exception\CheckpointException;
use Neos\EventStore\Model\Event\SequenceNumber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
abstract class AbstractCheckpointStorageTestBase extends TestCase
{
    abstract protected function createCheckpointStorage(string $subscriptionId): CheckpointStorageInterface;

    // --- Tests ----

    public function test_acquireLock_returns_first_sequenceNumber_when_first_called(): void
    {
        $checkpointStorage = $this->createCheckpointStorage('some-subscription');
        $sequenceNumber = $checkpointStorage->acquireLock();
        self::assertTrue($sequenceNumber->equals(SequenceNumber::none()));
    }

    public function test_acquireLock_fails_if_a_transaction_is_active_already(): void
    {
        $checkpointStorage1 = $this->createCheckpointStorage('some-subscription');

        $checkpointStorage2 = $this->createCheckpointStorage('some-other-subscription');
        $checkpointStorage3 = $this->createCheckpointStorage('some-subscription');
        $checkpointStorage1->acquireLock();
        $checkpointStorage2->acquireLock();

        $this->expectException(CheckpointException::class);
        $checkpointStorage3->acquireLock();
    }

    public function test_updateAndReleaseLock_fails_if_no_transaction_is_active(): void
    {
        $checkpointStorage = $this->createCheckpointStorage('some-subscription');

        $this->expectException(CheckpointException::class);
        $checkpointStorage->updateAndReleaseLock(SequenceNumber::fromInteger(123));
    }

    public function test_updateAndReleaseLock_fails_if_lock_was_released_previously(): void
    {
        $checkpointStorage = $this->createCheckpointStorage('some-subscription');
        $checkpointStorage->acquireLock();
        $checkpointStorage->updateAndReleaseLock(SequenceNumber::fromInteger(123));

        $this->expectException(CheckpointException::class);
        $checkpointStorage->updateAndReleaseLock(SequenceNumber::fromInteger(123));
    }

    public function test_getHighestAppliedSequenceNumber_returns_first_sequenceNumber_when_first_called(): void
    {
        $checkpointStorage = $this->createCheckpointStorage('some-subscription');

        $sequenceNumber = $checkpointStorage->getHighestAppliedSequenceNumber();
        self::assertTrue($sequenceNumber->equals(SequenceNumber::none()));
    }

    public function test_getHighestAppliedSequenceNumber_returns_updated_version_after_it_is_committed(): void
    {
        $checkpointStorage = $this->createCheckpointStorage('some-subscription');
        $checkpointStorage->acquireLock();
        $checkpointStorage->updateAndReleaseLock(SequenceNumber::fromInteger(2));
        self::assertSame($checkpointStorage->getHighestAppliedSequenceNumber()->value, 2);
    }

}
