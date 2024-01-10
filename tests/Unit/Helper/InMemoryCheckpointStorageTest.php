<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Unit\Helper;

use Neos\EventStore\CatchUp\CheckpointStorageInterface;
use Neos\EventStore\Helper\InMemoryCheckpointStorage;
use Neos\EventStore\Tests\Integration\AbstractCheckpointStorageTestBase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(InMemoryCheckpointStorage::class)]
final class InMemoryCheckpointStorageTest extends AbstractCheckpointStorageTestBase
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