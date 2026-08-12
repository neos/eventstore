<?php
declare(strict_types=1);
namespace Neos\EventStore\Tests\Unit\Helper;

use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Adapter\InMemoryEventStore;
use Neos\EventStore\Tests\Integration\AbstractEventStoreTestBase;
use Neos\EventStore\Tests\Integration\EventStoreFakeClock;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(InMemoryEventStore::class)]
final class InMemoryEventStoreTest extends AbstractEventStoreTestBase
{

    protected static function createEventStore(): EventStoreInterface
    {
        return new InMemoryEventStore(
            clock: EventStoreFakeClock::get()
        );
    }

    protected static function resetEventStore(): void
    {
        // every createEventStore() call returns a fresh in-memory instance, so there is no shared state to reset
    }
}
