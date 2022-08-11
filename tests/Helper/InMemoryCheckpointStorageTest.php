<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Helper;

use Neos\EventStore\CatchUp\CheckpointStorageInterface;
use Neos\EventStore\Helper\InMemoryCheckpointStorage;
use Neos\EventStore\Tests\AbstractCheckpointStorageTest;

final class InMemoryCheckpointStorageTest extends AbstractCheckpointStorageTest
{

    public function tearDown(): void
    {
        InMemoryCheckpointStorage::_resetTransactions();
    }

    protected function createCheckpointStorage(string $subscriptionId): CheckpointStorageInterface
    {
        return new InMemoryCheckpointStorage($subscriptionId);
    }
}
